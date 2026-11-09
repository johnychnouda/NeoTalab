<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\ShopTicket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends the shop owner a button ticket on their report WhatsApp number (Accept / Ready / Out / Done).
 * When that number is not set, the shop types ✅ / ready / out / done in the customer chat instead.
 */
class NotifyShopOfOrder implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10];

    public function __construct(public string $orderId) {}

    public function handle(ShopTicket $tickets): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order || ! $merchant) {
            return;
        }

        $ticket = $tickets->forOrder($merchant, $order);
        if (! $ticket) {
            return;
        }

        $to = $tickets->recipient($merchant);
        if ($to === '') {
            // No report number: the shop types ✅ / ready / out / done in the customer chat (coexistence).
            return;
        }

        // Prefer buttons; if Meta rejects them (#100 / outside the 24h window), fall back to plain text.
        try {
            $this->sendMessageAs($merchant, $to, $tickets->interactive($ticket['body'], $ticket['buttons']));
        } catch (\Throwable $e) {
            try {
                $this->sendAs($merchant, $to, $ticket['body']."\n\n".__('whatsapp.checkout.shop_reply', [
                    'shop' => $merchant->name,
                ], $merchant->locale ?? 'en'));
            } catch (\Throwable $e2) {
                // Report number often has no open WhatsApp window yet: the shop can still type ✅ in the customer chat.
                Log::info('Shop order ticket not delivered (use ✅ in the customer chat)', [
                    'order_id' => $order->id,
                    'interactive' => $e->getMessage(),
                    'text' => $e2->getMessage(),
                ]);
            }
        }
    }
}
