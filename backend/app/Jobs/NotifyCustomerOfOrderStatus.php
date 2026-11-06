<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\ChatMenu;
use App\Services\OrderTiming;
use App\Services\RatingFlow;
use App\Services\WhatsApp\CustomerTemplates;
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
            $order->status === 'picked_up' && app(OrderTiming::class)->inDays($merchant) => 'out_for_delivery',
            $order->status === 'picked_up' && ! $order->driver_id => 'out_for_delivery_eta',
            default => $order->status,
        };
        $locale = $order->customer->locale ?? $merchant->locale ?? 'en';
        if (! trans()->has($key, $locale) && ! trans()->has($key, 'en')) {
            return; // no customer message for this status
        }

        $reason = $order->reject_reason ?? $order->cancel_reason;

        // The rating question rides on the delivered message (one WhatsApp message instead of two).
        $askRating = $order->status === 'delivered' && ! $order->rating_requested_at;
        if ($askRating) {
            $order->forceFill(['rating_requested_at' => now()])->save();
        }

        try {
            $text = __($key, array_merge(OrderMessage::params($order, $merchant, $locale), [
                'eta' => $order->eta_mins ?? 30,
                'driver' => $order->driver?->name ?? '',
                // Customers get text only: a delivery address as typed, or the shop's address (never a link).
                'address' => $order->fulfillment === 'delivery'
                    ? OrderMessage::addressText($order)
                    : (ShopSettings::for($merchant)->pickupAddress() ?: $merchant->name),
                'reason' => $reason ? __('whatsapp.status.reason', ['reason' => $reason], $locale) : '',
                'when' => $order->promised_at ? app(OrderTiming::class)->phrase($merchant, $order, $locale) : '',
            ]), $locale).($order->payment_status === 'refund_due' ? "\n".__('whatsapp.status.refund', ['shop' => $merchant->name], $locale) : '');

            // Accepted / on the way: with the "Where's my order?" button. Days later, when the customer's
            // 24 hours are over, the approved template for the event goes instead (the others are skipped).
            // The delivered message carries the first of the one-tap rating questions (Great / Okay / Bad).
            $message = match (true) {
                $askRating => app(RatingFlow::class)->message($order, 'order', $locale, $text),
                in_array($order->status, ['confirmed', 'picked_up'], true) => ChatMenu::withStatusButton($order, $text, $locale),
                default => ['type' => 'text', 'text' => $text],
            };
            $sent = $this->sendCustomerAs($merchant, $order->customer->phone, $message, ...$this->template($key, $order, $merchant, $locale, $askRating));

            // The rating question was not delivered: ask again at the next chance, not never.
            if ($askRating && ! $sent) {
                $order->forceFill(['rating_requested_at' => null])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Order status notification failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * The template event and its variables for the statuses that matter to a customer who has gone quiet.
     *
     * @return array{0: ?string, 1: list<string>, 2: string}
     */
    protected function template(string $key, Order $order, Merchant $merchant, string $locale, bool $askRating = false): array
    {
        $shop = $merchant->name;
        $number = (string) $order->number;

        return match ($key) {
            'whatsapp.status.confirmed' => [CustomerTemplates::ACCEPTED, [$shop, $number, $order->promised_at ? app(OrderTiming::class)->phrase($merchant, $order, $locale) : ''], $locale],
            'whatsapp.status.ready_pickup' => [CustomerTemplates::READY, [$number, $shop, ShopSettings::for($merchant)->pickupAddress() ?: $shop], $locale],
            'whatsapp.status.out_for_delivery', 'whatsapp.status.out_for_delivery_eta', 'whatsapp.status.picked_up' => [CustomerTemplates::ON_THE_WAY, [$number, $shop], $locale],
            // Thank-you; with the rating question when the shop asks for one (the customer's number then opens the chat again).
            'whatsapp.status.delivered', 'whatsapp.status.collected' => [$askRating ? CustomerTemplates::DELIVERED_RATE : CustomerTemplates::DELIVERED, [$number, $shop], $locale],
            // A cancelled order the customer already paid for (Whish) says the money comes back.
            'whatsapp.status.rejected', 'whatsapp.status.cancelled', 'whatsapp.status.not_accepted' => [
                $order->payment_status === 'refund_due' ? CustomerTemplates::REFUND : CustomerTemplates::CANCELLED, [$number, $shop], $locale,
            ],
            default => [null, [], $locale],
        };
    }
}
