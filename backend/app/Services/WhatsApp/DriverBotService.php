<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\DriverDispatcher;
use App\Services\OrderService;
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
            ->get(['id', 'merchant_id', 'name', 'role', 'locale', 'phone', 'whatsapp_number', 'status', 'active_order_id', 'shift_started_at'])
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
        $locale = $driver->languageFor($merchant);
        $to = $driver->whatsapp_number ?: $driver->phone;

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
                $driverPhone = (string) ($driver->whatsapp_number ?: $driver->phone);
                app(CustomerMessenger::class)->send(
                    $merchant, (string) $order->customer?->phone,
                    ['type' => 'text', 'text' => __('whatsapp.status.outside', [
                        'number' => $order->number, 'driver' => $driver->name, 'phone' => OrderMessage::phone($driverPhone),
                    ], $customerLocale)],
                    CustomerTemplates::OUTSIDE, [$driver->name, (string) $order->number, $driverPhone], $customerLocale,
                );
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
