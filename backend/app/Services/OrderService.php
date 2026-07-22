<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * @return list<array<string,mixed>>
     */
    public function live(Merchant $merchant): array
    {
        return $this->mapOrders(
            Order::query()
                ->with(['customer', 'driver', 'items'])
                ->where('merchant_id', $merchant->id)
                ->whereNotIn('status', ['delivered', 'cancelled', 'rejected'])
                ->orderBy('created_at')
                ->get()
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(Merchant $merchant, ?string $status = null, ?string $date = null, int $limit = 50): array
    {
        $q = Order::query()
            ->with(['customer', 'driver', 'items'])
            ->where('merchant_id', $merchant->id);

        if ($status) {
            $q->where('status', $status);
        }
        if ($date) {
            $q->whereDate('created_at', $date);
        }

        return $this->mapOrders($q->latest()->limit($limit)->get());
    }

    public function transition(Merchant $merchant, string $orderId, string $action, array $payload = []): array
    {
        $order = Order::query()
            ->with(['customer', 'driver', 'items'])
            ->where('merchant_id', $merchant->id)
            ->findOrFail($orderId);

        return match ($action) {
            'accept' => $this->accept($order),
            'reject' => $this->reject($order, $payload['reason'] ?? null),
            'cancel' => $this->cancel($order, $payload['reason'] ?? null),
            'preparing' => $this->preparing($order, (int) ($payload['extraMins'] ?? 0)),
            'ready' => $this->ready($order),
            default => throw ValidationException::withMessages(['action' => ['Unknown order action.']]),
        };
    }

    protected function accept(Order $order): array
    {
        if ($order->status !== 'pending_payment') {
            throw ValidationException::withMessages(['order' => ['Order cannot be accepted in its current state.']]);
        }

        $order->forceFill(['status' => 'confirmed', 'accepted_at' => now()])->save();

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function reject(Order $order, ?string $reason): array
    {
        if (! in_array($order->status, ['pending_payment', 'confirmed'], true)) {
            throw ValidationException::withMessages(['order' => ['Order cannot be rejected in its current state.']]);
        }

        $order->forceFill(['status' => 'rejected', 'reject_reason' => $reason])->save();

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    protected function cancel(Order $order, ?string $reason): array
    {
        if (in_array($order->status, ['delivered', 'cancelled', 'rejected'], true)) {
            throw ValidationException::withMessages(['order' => ['Order cannot be cancelled in its current state.']]);
        }

        DB::transaction(function () use ($order, $reason) {
            if ($order->driver_id) {
                Driver::query()->where('id', $order->driver_id)->update([
                    'availability' => 'available',
                    'active_order_id' => null,
                ]);
            }

            $order->forceFill([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by' => 'merchant',
                'cancelled_at' => now(),
            ])->save();
        });

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
        if ($order->status !== 'preparing') {
            throw ValidationException::withMessages(['order' => ['Order must be preparing before marking ready.']]);
        }

        $order->forceFill(['status' => 'ready', 'ready_at' => now()])->save();

        return $this->mapOrder($order->fresh(['customer', 'driver', 'items']));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>|\Illuminate\Database\Eloquent\Collection<int, Order>  $orders
     * @return list<array<string,mixed>>
     */
    protected function mapOrders($orders): array
    {
        return $orders->map(fn (Order $o) => $this->mapOrder($o))->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function mapOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'total' => (float) $order->total,
            'payment_method' => $order->payment_method,
            'eta_mins' => $order->eta_mins,
            'customer_name' => $order->customer?->name,
            'customer_phone' => $order->customer?->phone,
            'driver_name' => $order->driver?->name,
            'driver_phone' => $order->driver?->phone,
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
