<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\OrderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The customer cancelled an order: every staff number is told, so nobody prepares it. The earlier
 * ticket still shows its Accept button, which DriverBotService answers with this same news.
 */
class NotifyStaffOfCancellation implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $orderId) {}

    public function handle(StaffMessenger $messenger): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order) {
            return;
        }

        $body = __('whatsapp.staff.cancelled', ['number' => $order->number], $merchant->locale ?? 'en');
        $body .= "\n".OrderMessage::phone($order->customer?->phone);

        foreach ($messenger->staff($merchant) as $member) {
            $messenger->send($merchant, $member, $body, []);
        }
    }
}
