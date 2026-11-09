<?php

namespace App\Services;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\CheckWhishTransfer;
use App\Jobs\NotifyShopOfOrder;
use App\Jobs\StartScheduledOrder;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\CustomerTemplates;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use App\Support\WhatsAppWindow;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * What happens once an order is accepted, straight away for cash, or when a Whish payment is
 * confirmed by the shop: hand it to a driver (delivery), send it to staff, and start the late-order timer.
 */
class OrderConfirmation
{
    public function __construct(
        protected DriverDispatcher $dispatcher,
        protected WhatsAppService $whatsapp,
        protected OrderService $orders,
        protected OrderTiming $timing,
    ) {}

    /**
     * Right after the customer confirms (and pays, for Whish): either wait for the shop, or go.
     */
    public function afterPlaced(Merchant $merchant, Order $order): void
    {
        if ($order->status !== 'placed') {
            $this->afterConfirmed($merchant, $order);

            return;
        }

        [$remind, $giveUp] = $this->timing->inDays($merchant) ? [30, 240] : [3, 10];
        CheckUnacceptedOrder::dispatch($order->id)->delay(now()->addMinutes($remind));
        CheckUnacceptedOrder::dispatch($order->id, true)->delay(now()->addMinutes($giveUp));
        NotifyShopOfOrder::dispatch($order->id);
    }

    /**
     * The shop tapped Accept: fix the promise, tell the customer, dispatch. A Whish order is
     * accepted but waits for its payment: the customer gets the instructions now, and the order
     * goes ahead (driver, staff ticket, arrival time) once the shop confirms the payment.
     */
    public function accept(Merchant $merchant, Order $order): Order
    {
        if ($order->payment_method === 'whish' && $order->payment_status === 'pending') {
            if (! $this->claim($order, 'pending', 'placed', ['status' => 'pending_payment', 'accepted_at' => now()])) {
                throw ValidationException::withMessages(['order' => [__('whatsapp.driver.not_yours', [], $merchant->locale ?? 'en')]]);
            }
            $order = $order->fresh(['customer']);
            $this->startWhishTransfer($merchant, $order);

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
        $due = $order->deliver_at && ! $this->timing->inDays($merchant) ? $order->deliver_at->copy()->subMinutes($this->timing->prepMins($merchant)) : null;
        if ($due && $due->isFuture()) {
            StartScheduledOrder::dispatch($order->id)->delay($due);

            return;
        }

        if ($order->fulfillment === 'delivery') {
            $this->dispatcher->assign($merchant, $order);
        }

        $this->lateCheck($merchant, $order);
        NotifyShopOfOrder::dispatch($order->id);
    }

    /** Food shops: the late check at the promised time . */
    public function lateCheck(Merchant $merchant, Order $order): void
    {
        // Food shops: if the order is not at the door by the promised time, the customer gets a
        // new time automatically (CheckLateOrder).
        $late = ShopSettings::for($merchant)->lateAlertMins();
        if ($late > 0 && ! $this->timing->inDays($merchant)) {
            CheckLateOrder::dispatch($order->id)->delay($this->timing->dueAt($order)->copy()->addMinutes(min($late, 5)));
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
            $when = $this->timing->phrase($merchant, $order, $locale);
            app(CustomerMessenger::class)->send(
                $merchant, (string) $order->customer?->phone,
                ['type' => 'text', 'text' => __('whatsapp.status.delayed', ['number' => $order->number, 'when' => $when], $locale)],
                CustomerTemplates::DELAYED, [(string) $order->number, $merchant->name, $when], $locale,
            );
        } catch (\Throwable $e) {
            Log::warning('Delay message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        return $order;
    }

    /** Cancel reason of an order the customer did not pay for in time. */
    public const WINDOW_CLOSED = 'payment window closed';

    /**
     * Whish-to-Whish: the customer pays the shop's Whish number with the order number in the note,
     * then taps "I paid". Nothing is marked paid until the shop has checked its own Whish account.
     */
    public function startWhishTransfer(Merchant $merchant, Order $order): void
    {
        $order->loadMissing('customer');
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';
        $body = __('whatsapp.checkout.whish_instructions', [
            'number' => $order->number,
            'amount' => OrderMessage::paymentLine($order, $merchant),
            'whish' => (string) ShopSettings::for($merchant)->whishNumber(),
            'shop' => $merchant->name,
            'minutes' => $this->window($merchant),
        ], $locale);

        $this->sendWithPaidButton($merchant, $order, $body, $locale, CustomerTemplates::PAY_INSTRUCTIONS, [
            $merchant->name, (string) $order->number, WhatsAppWindow::oneLine(OrderMessage::paymentLine($order, $merchant)),
            (string) ShopSettings::for($merchant)->whishNumber(), (string) $order->number, (string) $this->window($merchant),
        ]);

        // The customer has this long to tap "I paid"; after that the order is cancelled.
        CheckWhishTransfer::dispatch($order->id)->delay(now()->addMinutes($this->window($merchant)));
    }

    /** The customer tapped "I paid": the shop checks its Whish account. Also for an order cancelled for lack of payment, within a day. */
    public function customerPaid(Merchant $merchant, Order $order): void
    {
        $late = $order->status === 'cancelled'
            && $order->payment_status === 'failed'
            && $order->cancel_reason === self::WINDOW_CLOSED
            && $order->cancelled_at?->isAfter(now()->subDay());

        $claimed = $late
            ? $this->claim($order, 'failed', 'cancelled', ['payment_status' => 'awaiting_check'])
            : $this->claim($order, 'pending', 'pending_payment', ['payment_status' => 'awaiting_check']);
        if (! $claimed) {
            return; // already paid, cancelled for another reason, or already reported
        }

        $order = $order->fresh(['customer']);

        $this->tell($merchant, $order, 'whish_received');
        NotifyShopOfOrder::dispatch($order->id);
    }

    /** The shop checked its Whish account and found the payment: the order goes ahead (or is refunded, if it was already cancelled). False if it was already decided. */
    public function shopConfirms(Merchant $merchant, Order $order): bool
    {
        if ($order->status === 'cancelled') {
            if (! $this->claim($order, 'awaiting_check', 'cancelled', ['payment_status' => 'refund_due'])) {
                return false;
            }
            $this->tell($merchant, $order->fresh(), 'whish_late');

            return true;
        }

        if (! $this->claim($order, 'awaiting_check', 'pending_payment', [
            'payment_status' => 'paid', 'paid_at' => now(),
            'status' => 'confirmed', 'accepted_at' => $order->accepted_at ?? now(),
        ])) {
            return false;
        }

        $order = $order->fresh();
        $this->afterConfirmed($merchant, $order);
        $this->tell($merchant, $order, 'whish_paid');

        return true;
    }

    /** The shop did not find the payment: the order is cancelled and the customer is told. False if it was already decided. */
    public function shopRefuses(Merchant $merchant, Order $order): bool
    {
        if ($order->status === 'cancelled') {
            // Late payment on an order already cancelled: nothing to cancel again, the customer is told.
            if (! $this->claim($order, 'awaiting_check', 'cancelled', ['payment_status' => 'failed'])) {
                return false;
            }
            $this->tell($merchant, $order, 'whish_not_received');

            return true;
        }

        if (! $this->claim($order, 'awaiting_check', 'pending_payment', ['payment_status' => 'failed'])) {
            return false;
        }

        $this->orders->transition($merchant, $order->id, 'cancel', ['reason' => 'payment not received', 'by' => 'merchant', 'silent' => true]);
        $this->tell($merchant, $order, 'whish_not_received');

        return true;
    }

    /**
     * No "I paid" tap by the end of the window: the order is cancelled, and the customer can still
     * tap "I paid" for a day in case they paid late.
     */
    public function expireTransfer(Merchant $merchant, Order $order): void
    {
        if (! $this->claim($order, 'pending', 'pending_payment', ['payment_status' => 'failed'])) {
            return;
        }

        $this->orders->transition($merchant, $order->id, 'cancel', ['reason' => self::WINDOW_CLOSED, 'by' => 'customer', 'silent' => true]);
        $order = $order->fresh(['customer']);
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';
        $this->sendWithPaidButton($merchant, $order, __('whatsapp.checkout.whish_expired', [
            'number' => $order->number, 'eta' => $order->eta_mins, 'shop' => $merchant->name, 'when' => '',
        ], $locale), $locale, CustomerTemplates::PAY_EXPIRED, [(string) $order->number, $merchant->name]);
        if ($order->accepted_at) {
        }
    }

    /**
     * A text to the customer with the "I paid" button under it; after their 24 hours, the approved template with the same button.
     *
     * @param  list<string>  $templateParams
     */
    private function sendWithPaidButton(Merchant $merchant, Order $order, string $body, string $locale, ?string $event = null, array $templateParams = []): void
    {
        try {
            app(CustomerMessenger::class)->send($merchant, (string) $order->customer?->phone, ['type' => 'interactive', 'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['buttons' => [['type' => 'reply', 'reply' => [
                    'id' => "wh:paid:{$order->id}",
                    'title' => mb_substr(__('whatsapp.checkout.btn_whish_paid', [], $locale), 0, 20),
                ]]]],
            ]], $event, $templateParams, $locale, ["wh:paid:{$order->id}"]);
        } catch (\Throwable $e) {
            Log::warning('Whish message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function window(Merchant $merchant): int
    {
        return max(1, (int) (($merchant->settings ?? [])['wish_timeout_mins'] ?? 15));
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

    private function tell(Merchant $merchant, Order $order, string $key): void
    {
        $order->loadMissing('customer');
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';

        $when = $key === 'whish_paid' ? $this->timing->phrase($merchant, $order, $locale) : '';
        // The shop may check the payment a day later: the answer then goes as the approved template.
        [$event, $params] = match ($key) {
            'whish_paid' => [CustomerTemplates::PAY_CONFIRMED, [(string) $order->number, $merchant->name, $when]],
            'whish_not_received' => [CustomerTemplates::PAY_NOT_RECEIVED, [(string) $order->number, $merchant->name]],
            default => [null, []],
        };

        try {
            app(CustomerMessenger::class)->send($merchant, (string) $order->customer?->phone, [
                'type' => 'text',
                'text' => __("whatsapp.checkout.{$key}", [
                    'number' => $order->number,
                    'eta' => $order->eta_mins,
                    'shop' => $merchant->name,
                    'when' => $when,
                ], $locale),
            ], $event, $params, $locale);
        } catch (\Throwable $e) {
            Log::warning('Whish payment message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
