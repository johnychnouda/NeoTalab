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

        try {
            $this->sendMessageAs($merchant, $to, $tickets->interactive($ticket['body'], $ticket['buttons']));
        } catch (\Throwable $e) {
            Log::warning('Shop order ticket failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
