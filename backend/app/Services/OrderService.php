<?php

namespace App\Services;

use App\Jobs\NotifyCustomerOfOrderStatus;
use App\Jobs\NotifyDriverOfAssignment;
use App\Jobs\NotifyDriverOfCancellation;
use App\Jobs\NotifyStaffOfCancellation;
use App\Jobs\NotifyStaffOfRefund;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function transition(Merchant $merchant, string $orderId, string $action, array $payload = []): array
    {
        $order = Order::query()
            ->with(['customer', 'driver', 'items'])
            ->where('merchant_id', $merchant->id)
            ->findOrFail($orderId);

        $previousStatus = $order->status;

        $result = match ($action) {
            'accept' => $this->accept($order),
            'reject' => $this->reject($order, $payload['reason'] ?? null),
            'cancel' => $this->cancel($order, $payload['reason'] ?? null, ($payload['by'] ?? 'merchant') === 'customer' ? 'customer' : 'merchant'),
            'preparing' => $this->preparing($order, (int) ($payload['extraMins'] ?? 0)),
            'ready' => $this->ready($order),
            'assign' => $this->assign($order, (string) ($payload['driver_id'] ?? $payload['driverId'] ?? '')),
            'picked_up' => $this->pickedUp($order),
            'delivered' => $this->delivered($order),
            'failed' => $this->failed($order, $payload['reason'] ?? null),
            default => throw ValidationException::withMessages(['action' => ['Unknown order action.']]),
        };

        if (in_array($action, ['reject', 'cancel', 'failed'], true)) {
            $this->reverseCustomerTotals($order);
            $this->releaseOrder($order);
        }

        if ($result['status'] !== $previousStatus && empty($payload['silent'])) {
            $this->notify(new NotifyCustomerOfOrderStatus($order->id));
        }

        if ($action === 'assign') {
            $this->notify(new NotifyDriverOfAssignment($order->id));
        }

        if (in_array($action, ['delivered', 'reject', 'cancel', 'failed'], true)) {
            $this->afterDriverFreed($merchant, $order, $action);
        }

        // The staff have the ticket: tell them the customer cancelled, so nobody prepares it.
        if ($action === 'cancel' && ($payload['by'] ?? '') === 'customer' && empty($payload['silent'])) {
            $this->notify(new NotifyStaffOfCancellation($order->id));
        }

        return $result;
    }

    protected function accept(Order $order): array
    {
        // 'placed' = waiting for the shop to tap Accept.
        if (! in_array($order->status, ['placed', 'pending_payment'], true)) {
            throw ValidationException::withMessages(['order' => ['This order was already accepted or closed.']]);
        }

        $order->forceFill(['status' => 'confirmed', 'accepted_at' => now()])->save();

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function reject(Order $order, ?string $reason): array
    {
        if (! in_array($order->status, ['placed', 'pending_payment', 'confirmed'], true)) {
            throw ValidationException::withMessages(['order' => ['This order was already accepted or closed.']]);
        }

        if ($order->driver_id) {
            $this->releaseDriver($order->driver_id);
        }

        $order->forceFill(['status' => 'rejected', 'reject_reason' => $reason])->save();

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function cancel(Order $order, ?string $reason, string $by = 'merchant'): array
    {
        if (in_array($order->status, ['delivered', 'cancelled', 'rejected'], true)) {
            throw ValidationException::withMessages(['order' => ['Order cannot be cancelled in its current state.']]);
        }

        $driverId = $order->driver_id;

        DB::transaction(function () use ($order, $reason, $by) {
            if ($order->driver_id) {
                Driver::query()->where('id', $order->driver_id)->update([
                    'availability' => 'available',
                    'active_order_id' => null,
                ]);
            }

            $order->forceFill([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by' => $by,
                'cancelled_at' => now(),
            ])->save();
        });

        // The driver may already be on the way to collect it.
        if ($driverId) {
            $this->notify(new NotifyDriverOfCancellation($order->id, $driverId));
        }

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function preparing(Order $order, int $extraMins): array
    {
        if ($order->status !== 'confirmed') {
            throw ValidationException::withMessages(['order' => ['Order must be confirmed before preparing.']]);
        }

        $order->forceFill([
            'status' => 'preparing',
            'preparing_at' => now(),
            'eta_mins' => ($order->eta_mins ?? 30) + $extraMins,
        ])->save();

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function ready(Order $order): array
    {
        if (! in_array($order->status, ['confirmed', 'preparing'], true)) {
            throw ValidationException::withMessages(['order' => ['Only an accepted order can be marked ready.']]);
        }

        // A driver may already be attached while the kitchen was still preparing.
        $order->forceFill(['status' => $order->driver_id ? 'assigned' : 'ready', 'ready_at' => now()])->save();

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    /**
     * Hand the order to an on-duty driver. Re-assigning frees the previous driver.
     * A driver carries one order at a time (drivers.active_order_id).
     */
    protected function assign(Order $order, string $driverId): array
    {
        if (! in_array($order->status, ['confirmed', 'preparing', 'ready', 'assigned'], true)) {
            throw ValidationException::withMessages(['order' => ['Only confirmed orders can be assigned to a driver.']]);
        }

        DB::transaction(function () use ($order, $driverId) {
            $driver = Driver::query()->whereKey($driverId)->where('is_active', true)->lockForUpdate()->first();

            if (! $driver) {
                throw ValidationException::withMessages(['driver_id' => ['Driver not found.']]);
            }
            if ($driver->status !== 'on_duty') {
                throw ValidationException::withMessages(['driver_id' => ["{$driver->name} is not on duty."]]);
            }
            if ($driver->active_order_id && $driver->active_order_id !== $order->id) {
                throw ValidationException::withMessages(['driver_id' => ["{$driver->name} is already delivering another order."]]);
            }

            if ($order->driver_id && $order->driver_id !== $driver->id) {
                $this->releaseDriver($order->driver_id);
            }

            $driver->forceFill(['availability' => 'busy', 'active_order_id' => $order->id, 'last_assigned_at' => now()])->save();
            $order->forceFill([
                'driver_id' => $driver->id,
                // Keep kitchen progress visible; "assigned" only replaces "ready".
                'status' => $order->status === 'ready' ? 'assigned' : $order->status,
            ])->save();
        });

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function pickedUp(Order $order): array
    {
        // With the bot running the shop there are no kitchen steps: the driver collects the order
        // whenever it is handed over, so any accepted order with a driver can be picked up.
        // Shops without drivers deliver themselves: staff can send an order out with no driver attached.
        if ($order->fulfillment !== 'delivery' || ! in_array($order->status, ['confirmed', 'preparing', 'ready', 'assigned'], true)) {
            throw ValidationException::withMessages(['order' => ['Only an accepted delivery order can go out for delivery.']]);
        }

        $order->forceFill(['status' => 'picked_up', 'picked_up_at' => now()])->save();

        // Food shops: the arrival time is recalculated from the moment the order leaves the shop.
        $merchant = app(CurrentTenant::class)->get();
        $timing = app(OrderTiming::class);
        if ($merchant && ! $timing->inDays($merchant)) {
            $order->forceFill(['promised_at' => $timing->arrivalFromNow($merchant, $order)])->save();
        }

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    /**
     * The driver could not hand the order over (customer unreachable or refused). The order closes:
     * the driver is free, the customer is told, and the shop is told what happened.
     */
    protected function failed(Order $order, ?string $reason): array
    {
        if ($order->status !== 'picked_up') {
            throw ValidationException::withMessages(['order' => ['Only an order on its way can be marked as not delivered.']]);
        }

        $order->forceFill([
            'status' => 'failed',
            'cancel_reason' => trim((string) $reason) !== '' ? mb_substr(trim((string) $reason), 0, 200) : 'Customer not reachable',
            'cancelled_by' => 'driver',
            'cancelled_at' => now(),
        ])->save();
        if ($order->driver_id) {
            $this->releaseDriver($order->driver_id);
        }

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function delivered(Order $order): array
    {
        // Drivers often skip "Picked up" and tap "Delivered" straight away, accept any open order.
        if (! in_array($order->status, ['confirmed', 'preparing', 'ready', 'assigned', 'picked_up'], true)) {
            throw ValidationException::withMessages(['order' => ['This order is already closed.']]);
        }

        DB::transaction(function () use ($order) {
            if ($order->driver_id) {
                $this->releaseDriver($order->driver_id);
                Driver::query()->whereKey($order->driver_id)->increment('total_deliveries');

                if ($order->payment_method === 'cash') {
                    Driver::query()->whereKey($order->driver_id)->increment('cash_on_hand', (float) $order->total);
                }
            }

            $order->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
        });

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    /**
     * The status change is already saved; a notification problem must never fail the merchant's
     * action (on the sync queue the job runs inline and its exception would surface here).
     */
    protected function notify(object $job): void
    {
        try {
            dispatch($job)->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('Order notification could not be dispatched', ['job' => $job::class, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A driver may have become free: hand them the next waiting order.
     */
    protected function afterDriverFreed(Merchant $merchant, Order $order, string $action): void
    {
        if ($action === 'failed') {
            $messenger = app(StaffMessenger::class);
            $body = __('whatsapp.staff.not_delivered', ['number' => $order->number, 'reason' => $order->cancel_reason], $merchant->locale ?? 'en');
            foreach ($messenger->staff($merchant) as $member) {
                $messenger->send($merchant, $member, $body, []);
            }
        }

        try {
            app(DriverDispatcher::class)->dispatchWaiting($merchant);
        } catch (\Throwable $e) {
            Log::warning('Dispatching waiting orders failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }
    }

    protected function releaseDriver(string $driverId): void
    {
        Driver::query()->whereKey($driverId)->update(['availability' => 'available', 'active_order_id' => null]);
    }

    /**
     * Orders count toward the customer's totals when placed; undo that when they never happen.
     */
    /**
     * A cancelled or rejected order gives its tracked stock back, and a Whish payment that was
     * already taken is flagged so the shop refunds it (the customer is told in the status message).
     */
    protected function releaseOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($item->product_id) {
                Product::query()->whereKey($item->product_id)->where('track_inventory', true)
                    ->increment('stock_quantity', $item->quantity);
            }
        }

        if ($order->payment_status === 'paid') {
            $order->forceFill(['payment_status' => 'refund_due'])->save();
            // Cancelled or refused after the customer paid with Whish: the staff must refund it.
            $this->notify(new NotifyStaffOfRefund($order->id));
        } elseif ($order->payment_status === 'pending') {
            // A Whish payment that still arrives later is then caught as refund_due.
            $order->forceFill(['payment_status' => 'failed'])->save();
        }
    }

    protected function reverseCustomerTotals(Order $order): void
    {
        Customer::query()->whereKey($order->customer_id)
            ->where('total_orders', '>', 0)
            ->decrement('total_orders');

        Customer::query()->whereKey($order->customer_id)
            ->where('total_spent', '>=', $order->total)
            ->decrement('total_spent', (float) $order->total);
    }

    /**
     * @return array<string,mixed>
     */
    protected function mapOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'total' => (float) $order->total,
            'currency' => app(CurrentTenant::class)->get()?->currency,
            'payment_method' => $order->payment_method,
            'delivery_zone' => $order->delivery_zone_name,
            'delivery_address' => $order->delivery_address,
            'notes' => $order->notes,
            'eta_mins' => $order->eta_mins,
            'customer_name' => $order->customer?->name,
            'customer_phone' => $order->customer?->phone,
            'driver_id' => $order->driver_id,
            'driver_name' => $order->driver?->name,
            'driver_phone' => $order->driver?->phone,
            'driver_whatsapp' => $order->driver?->whatsapp_number ?: $order->driver?->phone,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'productName' => $i->product_name,
                'quantity' => $i->quantity,
                'unitPrice' => (float) $i->unit_price,
                'itemTotal' => (float) $i->item_total,
                'modifiers' => $i->modifiers,
            ])->all(),
        ];
    }
}
