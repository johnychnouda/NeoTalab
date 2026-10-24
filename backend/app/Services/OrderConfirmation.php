<?php

namespace App\Services;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\CheckWhishPayment;
use App\Jobs\NotifyStaffOfOrder;
use App\Jobs\NotifyStaffOfRefund;
use App\Jobs\StartScheduledOrder;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\CurrentTenant;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What happens once an order is accepted, straight away for cash, or when a Whish payment is
 * verified: hand it to a driver (delivery), send it to staff, and start the late-order timer.
 */
class OrderConfirmation
{
    public function __construct(
        protected DriverDispatcher $dispatcher,
        protected WhishPayService $whish,
        protected WhatsAppService $whatsapp,
        protected OrderService $orders,
        protected OrderTiming $timing,
    ) {}

    /**
     * Shops with staff accept each order themselves (Toters-style); shops without anyone who can
     * get the Accept ticket (no staff, or none inside WhatsApp's 24-hour window and the ticket
     * template not approved yet) keep the bot accepting straight away.
     */
    public function needsShopAcceptance(Merchant $merchant): bool
    {
        return app(StaffMessenger::class)->anyReachable($merchant, ShopTemplateService::NEW_ORDER);
    }

    /**
     * Right after the customer confirms (and pays, for Whish): either wait for the shop, or go.
     */
    public function afterPlaced(Merchant $merchant, Order $order): void
    {
        if ($order->status !== 'placed') {
            $this->afterConfirmed($merchant, $order);

            return;
        }

        NotifyStaffOfOrder::dispatch($order->id)->afterCommit();
        [$remind, $giveUp] = $this->timing->inDays($merchant) ? [30, 240] : [3, 10];
        CheckUnacceptedOrder::dispatch($order->id)->delay(now()->addMinutes($remind));
        CheckUnacceptedOrder::dispatch($order->id, true)->delay(now()->addMinutes($giveUp));
    }

    /**
     * The shop tapped Accept: fix the promise, tell the customer, dispatch. A Whish order is
     * accepted but waits for its payment: the customer gets the link now, and the order goes
     * ahead (driver, staff ticket, arrival time) once Whish confirms the payment.
     */
    public function accept(Merchant $merchant, Order $order): Order
    {
        if ($order->payment_method === 'whish' && $order->payment_status === 'pending') {
            if (! $this->claim($order, 'pending', 'placed', ['status' => 'pending_payment', 'accepted_at' => now()])) {
                throw ValidationException::withMessages(['order' => [__('whatsapp.driver.not_yours', [], $merchant->locale ?? 'en')]]);
            }
            $order = $order->fresh(['customer']);
            $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';
            $text = $this->startWhishPayment($merchant, $order, $locale, 'whish_accepted');
            try {
                $this->whatsapp->sendToCustomer($merchant, (string) $order->customer?->phone, ['type' => 'text', 'text' => $text]);
            } catch (\Throwable $e) {
                Log::warning('Whish link message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }

            return $order->fresh();
        }

        $order->forceFill(['promised_at' => $this->timing->promise($merchant, $order)])->save();
        $this->orders->transition($merchant, $order->id, 'accept');
        $order = $order->fresh();
        $this->afterConfirmed($merchant, $order);

        return $order;
    }

    public function afterConfirmed(Merchant $merchant, Order $order): void
    {
        if (! $order->promised_at) {
            $order->forceFill(['promised_at' => $this->timing->promise($merchant, $order)])->save();
        }

        // Chosen for later (flowers for Friday): the driver and the late check wait until it is due.
        $due = $order->deliver_at?->copy()->subMinutes($this->timing->prepMins($merchant));
        if ($due && $due->isFuture()) {
            NotifyStaffOfOrder::dispatch($order->id)->afterCommit();
            StartScheduledOrder::dispatch($order->id)->delay($due);

            return;
        }

        if ($order->fulfillment === 'delivery') {
            $this->dispatcher->assign($merchant, $order);
        }

        NotifyStaffOfOrder::dispatch($order->id)->afterCommit();
        $this->lateCheck($merchant, $order);
    }

    /** Food shops: the late check at the promised time (days shops use orders:due). */
    public function lateCheck(Merchant $merchant, Order $order): void
    {
        // Food shops: if the order is not at the door by the promised time, the customer gets a
        // new time automatically (CheckLateOrder). Shops that count in days use orders:due.
        $late = ShopSettings::for($merchant)->lateAlertMins();
        if ($late > 0 && ! $this->timing->inDays($merchant)) {
            CheckLateOrder::dispatch($order->id)->delay($order->promised_at->copy()->addMinutes(min($late, 5)));
        }
    }

    /**
     * Days shops: push the delivery to the next working day and tell the customer.
     */
    public function delay(Merchant $merchant, Order $order): Order
    {
        $base = $order->promised_at && $order->promised_at->isFuture() ? $order->promised_at : now();
        $order->forceFill([
            'promised_at' => $this->timing->nextWorkingDayEnd($merchant, $base),
            'eta_updates' => $order->eta_updates + 1,
            'due_reminded_at' => null,
        ])->save();

        $order->loadMissing('customer');
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';
        try {
            $this->whatsapp->sendToCustomer($merchant, (string) $order->customer?->phone, ['type' => 'text', 'text' => __('whatsapp.status.delayed', [
                'number' => $order->number,
                'when' => $this->timing->phrase($merchant, $order, $locale),
            ], $locale)]);
        } catch (\Throwable $e) {
            Log::warning('Delay message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        return $order;
    }

    /**
     * Creates the Whish payment link and schedules the payment checks; returns the message for
     * the customer. If Whish cannot make a link, the order is cancelled and the customer is told
     * to pay cash instead.
     */
    public function startWhishPayment(Merchant $merchant, Order $order, string $locale, string $key = 'whish_link'): string
    {
        try {
            $url = $this->whish->createLink($merchant, $order);
        } catch (\Throwable $e) {
            Log::warning('Whish link failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            $this->orders->transition($merchant, $order->id, 'cancel', ['reason' => null, 'by' => 'customer', 'silent' => true]);

            return __('whatsapp.checkout.whish_unavailable', ['shop' => $merchant->name], $locale);
        }

        // Checks during the payment window, in case Whish's callback never reaches us, so a paying
        // customer waits minutes, not the whole window; the last one closes the window.
        $window = max(1, (int) (($merchant->settings ?? [])['wish_timeout_mins'] ?? 15));
        foreach ([1, 3, 6, 10, 20, 30, 45] as $minute) {
            if ($minute < $window) {
                CheckWhishPayment::dispatch($order->id)->delay(now()->addMinutes($minute));
            }
        }
        CheckWhishPayment::dispatch($order->id, true)->delay(now()->addMinutes($window));

        return __("whatsapp.checkout.{$key}", [
            'number' => $order->number,
            'amount' => OrderMessage::paymentLine($order, $merchant),
            'url' => $url,
            'minutes' => $window,
            'shop' => $merchant->name,
        ], $locale);
    }

    /**
     * Ask Whish whether a pending order was paid and act on it. $final = the payment window is
     * over: a still-pending payment then cancels the order. Returns the resulting payment status.
     *
     * Safe to run concurrently (callback + checks): each outcome is claimed with a conditional
     * update, so an order is confirmed or cancelled exactly once. A payment that Whish confirms
     * after the order was already cancelled is flagged refund_due instead of being lost.
     */
    public function settleWhish(Merchant $merchant, Order $order, bool $final = false): string
    {
        if ($order->payment_method !== 'whish') {
            return (string) $order->payment_status;
        }

        $pending = $order->payment_status === 'pending' && $order->status === 'pending_payment';
        $expired = $order->payment_status === 'failed' && $order->status === 'cancelled';
        if (! $pending && ! $expired) {
            return (string) $order->payment_status;
        }

        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);

        try {
            $status = $this->whish->status($merchant, $order);

            if ($expired) {
                if ($status === 'success' && $this->claim($order, 'failed', 'cancelled', ['payment_status' => 'refund_due', 'paid_at' => now()])) {
                    Log::warning('Whish payment received for a cancelled order', ['order_id' => $order->id]);
                    $this->tell($merchant, $order, 'whish_late');
                    NotifyStaffOfRefund::dispatch($order->id)->afterCommit();

                    return 'refund_due';
                }

                return (string) $order->fresh()->payment_status;
            }

            if ($status === 'success') {
                // The order is already accepted (by the shop, or by the bot): it goes ahead now.
                if (! $this->claim($order, 'pending', 'pending_payment', [
                    'payment_status' => 'paid', 'paid_at' => now(),
                    'status' => 'confirmed', 'accepted_at' => $order->accepted_at ?? now(),
                ])) {
                    return (string) $order->fresh()->payment_status; // another check got there first
                }
                $order = $order->fresh();
                $this->afterConfirmed($merchant, $order);
                $this->tell($merchant, $order->fresh(), 'whish_paid');

                return 'paid';
            }

            if ($status === 'unknown' && $final) {
                // Whish could not answer: never cancel on a guess, the job retries.
                throw new RuntimeException("Whish status unavailable for order {$order->id}");
            }

            if ($status === 'failed' || ($final && $status === 'pending')) {
                if (! $this->claim($order, 'pending', 'pending_payment', ['payment_status' => 'failed'])) {
                    return (string) $order->fresh()->payment_status;
                }
                $this->orders->transition($merchant, $order->id, 'cancel', ['reason' => null, 'by' => 'customer', 'silent' => true]);
                $this->tell($merchant, $order, $status === 'failed' ? 'whish_failed' : 'whish_expired');
                if ($order->accepted_at) {
                    $this->tellStaffUnpaid($merchant, $order);
                }

                return 'failed';
            }

            return 'pending';
        } finally {
            $tenant->set($previous);
        }
    }

    /**
     * Atomically moves the order out of the given payment/order state; false if it already moved.
     *
     * @param  array<string, mixed>  $changes
     */
    private function claim(Order $order, string $paymentStatus, string $status, array $changes): bool
    {
        return Order::query()->whereKey($order->id)
            ->where('payment_status', $paymentStatus)
            ->where('status', $status)
            ->update($changes + ['updated_at' => now()]) === 1;
    }

    /** The shop accepted the order, but the customer never paid: it must not be prepared. */
    private function tellStaffUnpaid(Merchant $merchant, Order $order): void
    {
        $messenger = app(StaffMessenger::class);
        $text = __('whatsapp.staff.whish_unpaid', ['number' => $order->number], $merchant->locale ?? 'en');
        foreach ($messenger->staff($merchant) as $member) {
            $messenger->send($merchant, $member, $text, []);
        }
    }

    private function tell(Merchant $merchant, Order $order, string $key): void
    {
        $order->loadMissing('customer');
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';

        try {
            $this->whatsapp->sendToCustomer($merchant, (string) $order->customer?->phone, [
                'type' => 'text',
                'text' => __("whatsapp.checkout.{$key}", [
                    'number' => $order->number,
                    'eta' => $order->eta_mins,
                    'shop' => $merchant->name,
                    'when' => $key === 'whish_paid' ? $this->timing->phrase($merchant, $order, $locale) : '',
                ], $locale),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Whish payment message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
