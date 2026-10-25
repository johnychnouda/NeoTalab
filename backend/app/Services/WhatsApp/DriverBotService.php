<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Services\DriverDispatcher;
use App\Services\OrderConfirmation;
use App\Services\OrderService;
use App\Services\OrderTiming;
use App\Services\QuoteService;
use App\Support\Money;
use App\Support\OrderMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Messages from a registered driver's number to the shop's number:
 * "start" / "stop" shift commands and the Picked up / Delivered buttons on each delivery.
 */
class DriverBotService
{
    public function __construct(
        protected OrderService $orders,
        protected DriverDispatcher $dispatcher,
        protected WhatsAppService $whatsapp,
    ) {}

    public static function findDriver(string $phone): ?Driver
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        return Driver::query()
            ->where('is_active', true)
            ->get(['id', 'merchant_id', 'name', 'role', 'phone', 'whatsapp_number', 'status', 'active_order_id', 'shift_started_at'])
            ->first(fn (Driver $d) => in_array($digits, [
                preg_replace('/\D+/', '', (string) $d->whatsapp_number),
                preg_replace('/\D+/', '', (string) $d->phone),
            ], true));
    }

    /**
     * @param  array<string, mixed>  $msg  raw Meta message
     */
    public function handle(Merchant $merchant, Driver $driver, array $msg): void
    {
        $locale = $merchant->locale ?? 'en';
        $to = $driver->whatsapp_number ?: $driver->phone;

        if ($driver->role === 'staff') {
            $this->reply($merchant, $to, $this->handleStaff($merchant, $msg, $locale));

            return;
        }

        $buttonId = $msg['interactive']['button_reply']['id'] ?? $msg['button']['payload'] ?? null;
        if (is_string($buttonId) && preg_match('/^drv:(picked_up|outside|delivered|failed):(.+)$/', $buttonId, $m)) {
            $this->reply($merchant, $to, $this->orderAction($merchant, $driver, $m[1], $m[2], $locale));

            return;
        }

        $text = mb_strtolower(trim((string) ($msg['text']['body'] ?? $msg['button']['text'] ?? '')));

        if (in_array($text, ['start', 'بدء', 'ابدأ', 'debut', 'début', 'commencer'], true)) {
            $started = $driver->status !== 'on_duty';
            $this->reply($merchant, $to, $this->startShift($driver, $locale));

            // After the greeting, hand over any orders that were waiting for a driver.
            if ($started) {
                $this->dispatcher->dispatchWaiting($merchant);
            }

            return;
        }

        $reply = match (true) {
            in_array($text, ['stop', 'end', 'إنهاء', 'وقف', 'fin', 'terminer'], true) => $this->endShift($merchant, $driver, $locale),
            default => __('whatsapp.driver.help', [], $locale),
        };

        $this->reply($merchant, $to, $reply);
    }

    /**
     * Staff (owner's personal phone, employees): open/close the shop, and the order and
     * hand-back buttons sent by NotifyStaffOfOrder / NotifyStaffOfHandoff.
     *
     * @param  array<string, mixed>  $msg
     */
    protected function handleStaff(Merchant $merchant, array $msg, string $locale): string
    {
        // Buttons on a template message (sent outside the 24-hour window) come back as a payload.
        $buttonId = (string) ($msg['interactive']['button_reply']['id'] ?? $msg['button']['payload'] ?? '');

        // "refunded 128": the shop has sent the money back to a customer whose cancelled Whish order was paid.
        $typed = trim((string) ($msg['text']['body'] ?? ''));

        // "quote 12 45 Screen replacement" (or the shop's ticket "quote 45 …"): the price for a customer's request.
        if ($buttonId === '' && ($quote = QuoteService::parse($typed, true)) !== null) {
            return $this->sendQuote($merchant, $quote, $locale);
        }
        if (preg_match('/^(?:refunded|refund done|مسترد|remboursé|rembourse)\s*#?\s*(\d+)$/iu', $typed, $m)) {
            $order = Order::query()->where('number', (int) $m[1])->where('payment_status', 'refund_due')->first();
            if (! $order) {
                return __('whatsapp.staff.refund_not_found', ['number' => (int) $m[1]], $locale);
            }
            $order->forceFill(['payment_status' => 'refunded'])->save();

            return __('whatsapp.staff.refund_done', ['number' => $order->number], $locale);
        }

        if (preg_match('/^stf:(approve|refuse):([^:]+):(.+)$/', $buttonId, $m)) {
            $customer = Customer::query()->whereKey($m[2])->first();
            $product = Product::query()->find($m[3]);
            if (! $customer || ! $product) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }

            return $this->answerCheck($merchant, $customer, $product, $m[1] === 'approve', $locale);
        }

        if (preg_match('/^stf:resume:(.+)$/', $buttonId, $m)) {
            $customer = Customer::query()->whereKey($m[1])->first();
            $customer?->forceFill(['bot_paused_until' => null])->save();

            return __('whatsapp.staff.resumed', ['phone' => OrderMessage::phone($customer?->phone)], $locale);
        }

        if (preg_match('/^stf:appt_(completed|no_show):(.+)$/', $buttonId, $m)) {
            $appointment = Appointment::query()->whereKey($m[2])->first();
            if (! $appointment) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }
            // Only a booking still on the calendar can be marked done or missed (not one the customer cancelled).
            if ($appointment->status !== 'booked') {
                return __('whatsapp.appointment.staff_closed', ['service' => $appointment->service_name], $locale);
            }
            $appointment->forceFill(['status' => $m[1]])->save();

            return __('whatsapp.appointment.staff_updated', ['service' => $appointment->service_name], $locale);
        }

        if (preg_match('/^stf:(accept|reject|delay):(.+)$/', $buttonId, $m)) {
            $order = Order::query()->whereKey($m[2])->first();
            if (! $order) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }
            if ($order->status === 'cancelled') {
                return __('whatsapp.staff.cancelled', ['number' => $order->number], $locale);
            }

            try {
                if ($m[1] === 'accept') {
                    $order = app(OrderConfirmation::class)->accept($merchant, $order);

                    // Whish: the customer pays now; the next ticket comes once the payment is in.
                    // Otherwise the next ticket (Ready / Out for delivery) confirms it to the staff.
                    return $order->status === 'pending_payment'
                        ? __('whatsapp.staff.accepted_awaiting_payment', ['number' => $order->number], $locale)
                        : '';
                }
                if ($m[1] === 'reject') {
                    $this->orders->transition($merchant, $order->id, 'reject');

                    return __('whatsapp.staff.refused', ['number' => $order->number], $locale);
                }
                if (in_array($order->status, ['delivered', 'cancelled', 'rejected'], true)) {
                    return __('whatsapp.driver.not_yours', [], $locale);
                }
                $order = app(OrderConfirmation::class)->delay($merchant, $order);

                return __('whatsapp.staff.delayed', ['number' => $order->number, 'date' => app(OrderTiming::class)->date($merchant, $order->promised_at, $locale)], $locale);
            } catch (ValidationException $e) {
                return collect($e->errors())->flatten()->first() ?? __('whatsapp.error', [], $locale);
            }
        }

        if (preg_match('/^stf:(ready|picked_up|delivered):(.+)$/', $buttonId, $m)) {
            $order = Order::query()->whereKey($m[2])->first();
            if (! $order) {
                return __('whatsapp.driver.not_yours', [], $locale);
            }

            try {
                $this->orders->transition($merchant, $order->id, $m[1]);
            } catch (ValidationException $e) {
                return collect($e->errors())->flatten()->first() ?? __('whatsapp.error', [], $locale);
            }

            return __('whatsapp.staff.done', ['number' => $order->number], $locale);
        }

        $text = mb_strtolower(trim((string) ($msg['text']['body'] ?? '')));
        $mode = match (true) {
            in_array($text, ['open', 'فتح', 'افتح', 'ouvrir', 'ouvert'], true) => 'auto',
            in_array($text, ['close', 'closed', 'سكر', 'إغلاق', 'اغلاق', 'fermer', 'fermé'], true) => 'closed',
            default => null,
        };

        if ($mode === null) {
            return __('whatsapp.staff.help', [], $locale);
        }

        $settings = $merchant->settings ?? [];
        $settings['mode'] = $mode;
        $merchant->forceFill(['settings' => $settings])->save();

        return __($mode === 'auto' ? 'whatsapp.staff.opened' : 'whatsapp.staff.closed', ['shop' => $merchant->name], $locale);
    }

    /**
     * The team approved or refused an item for a customer: the customer is told
     * and, when approved, gets a button to add it (the approval lasts a day, for one order).
     */
    public function answerCheck(Merchant $merchant, Customer $customer, Product $product, bool $approve, string $locale): string
    {
        $customerLocale = $customer->locale ?? 'en';
        if ($approve) {
            $customer->markCheck($product->id, true);
        } else {
            $customer->useApproval($product->id);
        }
        $customer->forceFill(['bot_paused_until' => null])->save();

        $text = __($approve ? 'whatsapp.check.approved' : 'whatsapp.check.refused', ['item' => $product->name, 'shop' => $merchant->name], $customerLocale);
        $message = $approve
            ? ['type' => 'interactive', 'interactive' => ['type' => 'button', 'body' => ['text' => $text], 'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => "mn:item:{$product->id}", 'title' => mb_substr(__('whatsapp.check.button_add', [], $customerLocale), 0, 20)]],
            ]]]]
            : ['type' => 'text', 'text' => $text];
        try {
            $this->whatsapp->sendToCustomer($merchant, (string) $customer->phone, $message);
        } catch (\Throwable $e) {
            Log::warning('Check answer not delivered to the customer', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }

        return __($approve ? 'whatsapp.check.staff_approved' : 'whatsapp.check.staff_refused', ['item' => $product->name, 'phone' => OrderMessage::phone($customer->phone)], $locale);
    }

    /** The shop's price for the customer's latest open request; the reply is for the staff member. */
    protected function sendQuote(Merchant $merchant, array $quote, string $locale): string
    {
        $request = $quote['number'] !== null
            ? Quote::query()->where('number', $quote['number'])->first()
            : Quote::query()->where('status', 'requested')->latest()->first();
        if (! $request || ! in_array($request->status, ['requested', 'sent'], true)) {
            return __('whatsapp.quote.staff_none', [], $locale);
        }
        if ($quote['price'] < 1 || $quote['price'] > 100000) {
            return __('whatsapp.quote.staff_bad_price', [], $locale);
        }
        $sent = app(QuoteService::class)->send($merchant, $request, $quote['price'], $quote['title']);

        return $sent
            ? __('whatsapp.quote.staff_sent', ['number' => $sent->number, 'price' => Money::format((float) $sent->price, $merchant->currency)], $locale)
            : __('whatsapp.quote.staff_none', [], $locale);
    }

    protected function startShift(Driver $driver, string $locale): string
    {
        if ($driver->status === 'on_duty') {
            return __('whatsapp.driver.already_on_shift', [], $locale);
        }

        $driver->forceFill(['status' => 'on_duty', 'shift_started_at' => now()])->save();

        return __('whatsapp.driver.shift_started', ['name' => $driver->name], $locale);
    }

    protected function endShift(Merchant $merchant, Driver $driver, string $locale): string
    {
        if ($driver->active_order_id) {
            $number = Order::query()->whereKey($driver->active_order_id)->value('number');

            return __('whatsapp.driver.shift_busy', ['number' => $number], $locale);
        }

        $since = $driver->shift_started_at ?? now()->startOfDay();
        $delivered = Order::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'delivered')
            ->where('delivered_at', '>=', $since)
            ->get(['total', 'total_lbp', 'cash_main', 'payment_method', 'payment_currency']);

        $driver->forceFill(['status' => 'off_duty', 'shift_started_at' => null])->save();

        return __('whatsapp.driver.shift_ended', [
            'name' => $driver->name,
            'count' => $delivered->count(),
            'cash' => $this->cashSummary($merchant, $delivered, $locale),
        ], $locale);
    }

    /**
     * Cash collected per currency, e.g. "36.00 USD + 1,074,000 LBP".
     */
    protected function cashSummary(Merchant $merchant, $orders, string $locale): string
    {
        $cash = $orders->where('payment_method', 'cash');
        $lbp = $cash->whereIn('payment_currency', ['LBP', 'MIXED'])->sum(fn ($o) => (float) $o->total_lbp);
        $main = $cash->sum(fn ($o) => match ($o->payment_currency) {
            'LBP' => 0.0,
            'MIXED' => (float) $o->cash_main,
            default => (float) $o->total,
        });

        $parts = [];
        if ($main > 0) {
            $parts[] = Money::format($main, $merchant->currency);
        }
        if ($lbp > 0) {
            $parts[] = Money::lbp($lbp);
        }

        return $parts ? implode(' + ', $parts) : __('whatsapp.driver.nothing', [], $locale);
    }

    protected function orderAction(Merchant $merchant, Driver $driver, string $action, string $orderId, string $locale): string
    {
        $order = Order::query()->whereKey($orderId)->first();
        if (! $order || $order->driver_id !== $driver->id) {
            return __('whatsapp.driver.not_yours', [], $locale);
        }

        // "I'm outside": the customer is told the driver is at the door (no status change).
        if ($action === 'outside') {
            $order->loadMissing('customer');
            $customerLocale = $order->customer?->locale ?? $locale;
            try {
                $this->whatsapp->sendToCustomer($merchant, (string) $order->customer?->phone, ['type' => 'text', 'text' => __('whatsapp.status.outside', [
                    'number' => $order->number, 'driver' => $driver->name, 'phone' => OrderMessage::phone($driver->whatsapp_number ?: $driver->phone),
                ], $customerLocale)]);
            } catch (\Throwable $e) {
                Log::warning('Driver outside message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }

            return __('whatsapp.driver.customer_told', [], $locale);
        }

        try {
            $this->orders->transition($merchant, $order->id, $action);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first() ?? __('whatsapp.error', [], $locale);
        }

        if ($action === 'picked_up') {
            // The next card: "I'm outside" when arriving, then "Delivered".
            $this->sendOnTheWayCard($merchant, $driver, $order->fresh(), $locale);

            return '';
        }

        if ($action === 'failed') {
            return __('whatsapp.driver.failed_closed', ['number' => $order->number], $locale);
        }

        return __('whatsapp.driver.delivered', ['number' => $order->number], $locale);
    }

    protected function sendOnTheWayCard(Merchant $merchant, Driver $driver, Order $order, string $locale): void
    {
        $body = __('whatsapp.driver.on_the_way', OrderMessage::forDriver($order, $merchant, $locale), $locale);
        try {
            $this->whatsapp->sendToCustomer($merchant, $driver->whatsapp_number ?: $driver->phone, ['type' => 'interactive', 'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => "drv:outside:{$order->id}", 'title' => mb_substr(__('whatsapp.driver.button_outside', [], $locale), 0, 20)]],
                    ['type' => 'reply', 'reply' => ['id' => "drv:delivered:{$order->id}", 'title' => mb_substr(__('whatsapp.driver.button_delivered', [], $locale), 0, 20)]],
                    ['type' => 'reply', 'reply' => ['id' => "drv:failed:{$order->id}", 'title' => mb_substr(__('whatsapp.driver.button_failed', [], $locale), 0, 20)]],
                ]],
            ]]);
        } catch (\Throwable $e) {
            Log::warning('Driver on-the-way card failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    protected function reply(Merchant $merchant, string $to, string $text): void
    {
        if ($text === '') {
            return;
        }

        try {
            $this->whatsapp->sendToCustomer($merchant, $to, ['type' => 'text', 'text' => $text]);
        } catch (\Throwable $e) {
            Log::warning('Driver reply failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }
    }
}
