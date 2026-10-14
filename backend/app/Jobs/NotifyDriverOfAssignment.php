<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Support\OrderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends the delivery to the assigned driver from the shop's number, with Picked up / Delivered
 * buttons (handled by DriverBotService). Drivers message the shop with "start" at the beginning
 * of a shift, which keeps them inside Meta's 24-hour window for these messages.
 */
class NotifyDriverOfAssignment implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [15];

    public function __construct(public string $orderId) {}

    public function handle(): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        $to = $order?->driver?->whatsapp_number ?: $order?->driver?->phone;
        if (! $order || ! $to) {
            return;
        }

        $locale = $merchant->locale ?? 'en';
        $body = __('whatsapp.driver.assigned', OrderMessage::forDriver($order, $merchant, $locale), $locale);

        try {
            $this->sendMessageAs($merchant, $to, [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => mb_substr($body, 0, 1024)],
                    'action' => ['buttons' => [
                        ['type' => 'reply', 'reply' => ['id' => "drv:picked_up:{$order->id}", 'title' => __('whatsapp.driver.button_picked_up', [], $locale)]],
                        ['type' => 'reply', 'reply' => ['id' => "drv:delivered:{$order->id}", 'title' => __('whatsapp.driver.button_delivered', [], $locale)]],
                    ]],
                ],
            ]);

            if ($location = OrderMessage::locationMessage($order)) {
                $this->sendMessageAs($merchant, $to, $location);
            }
        } catch (\Throwable $e) {
            Log::warning('Driver delivery message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
