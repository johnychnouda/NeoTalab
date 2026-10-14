<?php

namespace App\Console\Commands;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\OrderConfirmation;
use App\Services\OrderTiming;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\CurrentTenant;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Shops that deliver in days (clothes, gadgets, made to order). Hourly:
 * - from 9:00 on the promised day, staff get "due today" with Out for delivery / Delay 1 day;
 * - an order still open after its promised day moves to the next working day and the
 *   customer gets an apology with the new date.
 */
class OrdersDue extends Command
{
    use SendsMerchantWhatsApp;

    protected $signature = 'orders:due';

    protected $description = 'Remind days-based shops of orders due today and reschedule overdue ones.';

    public const OPEN = ['confirmed', 'preparing', 'ready', 'assigned', 'picked_up'];

    public function handle(OrderConfirmation $confirmation, OrderTiming $timing): int
    {
        $tenant = app(CurrentTenant::class);

        foreach (Merchant::query()->where('status', 'active')->get() as $merchant) {
            if (ShopSettings::for($merchant)->timingUnit() !== 'days') {
                continue;
            }
            $tenant->set($merchant);
            try {
                $local = now()->setTimezone($merchant->timezone ?: config('app.timezone'));

                foreach (Order::query()->whereIn('status', self::OPEN)->whereNotNull('promised_at')->where('promised_at', '<', now())->get() as $order) {
                    $confirmation->delay($merchant, $order);
                }

                if ($local->hour < 9) {
                    continue;
                }
                $due = Order::query()->whereIn('status', array_diff(self::OPEN, ['picked_up']))
                    ->whereBetween('promised_at', [$local->copy()->startOfDay()->utc(), $local->copy()->endOfDay()->utc()])
                    ->whereNull('due_reminded_at')->with('customer')->get();
                foreach ($due as $order) {
                    $this->remind($merchant, $order);
                }
            } catch (\Throwable $e) {
                Log::error('orders:due failed for merchant', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            } finally {
                $tenant->set(null);
            }
        }

        return self::SUCCESS;
    }

    protected function remind(Merchant $merchant, Order $order): void
    {
        $order->forceFill(['due_reminded_at' => now()])->save();
        $locale = $merchant->locale ?? 'en';
        $body = __('whatsapp.staff.due_today', OrderMessage::params($order, $merchant, $locale), $locale);
        $buttons = [
            ['stf:picked_up', __('whatsapp.staff.button_out', [], $locale)],
            ['stf:delay', __('whatsapp.staff.button_delay', [], $locale)],
        ];
        if ($order->fulfillment === 'pickup') {
            $buttons[0] = ['stf:ready', __('whatsapp.staff.button_ready', [], $locale)];
        }

        $buttons = array_map(fn ($b) => ["{$b[0]}:{$order->id}", $b[1]], $buttons);
        // The ticket without its first line ("📦 Order #12 is due today"), which the template already says.
        $details = StaffMessenger::oneLine(implode("\n", array_slice(explode("\n", $body), 1)));
        $messenger = app(StaffMessenger::class);
        foreach ($messenger->staff($merchant) as $member) {
            $messenger->send($merchant, $member, $body, $buttons, ShopTemplateService::ORDER_DUE, [(string) $order->number, $merchant->name, $details]);
        }
    }
}
