<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\ChatMenu;
use App\Services\OrderTiming;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tells the customer on WhatsApp that their order changed status. Queued so a slow or failing
 * Meta API never blocks the merchant's dashboard action.
 */
class NotifyCustomerOfOrderStatus implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public string $orderId) {}

    public function handle(): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order || ! $order->customer?->phone) {
            return;
        }

        $key = 'whatsapp.status.'.match (true) {
            $order->fulfillment === 'pickup' && $order->status === 'ready' => 'ready_pickup',
            $order->fulfillment === 'pickup' && $order->status === 'delivered' => 'collected',
            $order->status === 'picked_up' && (! $order->driver_id || app(OrderTiming::class)->inDays($merchant)) => 'out_for_delivery',
            default => $order->status,
        };
        $locale = $order->customer->locale ?? $merchant->locale ?? 'en';
        if (! trans()->has($key, $locale) && ! trans()->has($key, 'en')) {
            return; // no customer message for this status
        }

        $reason = $order->reject_reason ?? $order->cancel_reason;

        // The rating question rides on the delivered message (one WhatsApp message instead of two).
        $askRating = $order->status === 'delivered' && ! $order->rating_requested_at && ShopSettings::for($merchant)->asksForRating();
        if ($askRating) {
            $order->forceFill(['rating_requested_at' => now()])->save();
        }

        try {
            $text = __($key, array_merge(OrderMessage::params($order, $merchant, $locale), [
                'eta' => $order->eta_mins ?? 30,
                'driver' => $order->driver?->name ?? '',
                'address' => ShopSettings::for($merchant)->pickupAddress() ?: $merchant->name,
                'reason' => $reason ? __('whatsapp.status.reason', ['reason' => $reason], $locale) : '',
                'when' => $order->promised_at ? app(OrderTiming::class)->phrase($merchant, $order, $locale) : '',
            ]), $locale).($order->payment_status === 'refund_due' ? "\n".__('whatsapp.status.refund', ['shop' => $merchant->name], $locale) : '')
                .($askRating ? "\n\n".__('whatsapp.rating.ask', [], $locale) : '');

            // Accepted / on the way: with the "Where's my order?" button.
            $this->sendMessageAs($merchant, $order->customer->phone, in_array($order->status, ['confirmed', 'picked_up'], true)
                ? ChatMenu::withStatusButton($order, $text, $locale)
                : ['type' => 'text', 'text' => $text]);
        } catch (\Throwable $e) {
            Log::warning('Order status notification failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
