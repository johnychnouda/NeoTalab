<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\OrderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A Whish payment must go back to the customer (the order was cancelled or refused after it was
 * paid, or the payment arrived after the order was cancelled): tell every staff number.
 */
class NotifyStaffOfRefund implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $orderId) {}

    public function handle(StaffMessenger $messenger): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order || $order->payment_status !== 'refund_due') {
            return;
        }

        $amount = OrderMessage::paymentLine($order, $merchant);
        $phone = (string) $order->customer?->phone;
        $body = __('whatsapp.staff.whish_refund', ['number' => $order->number, 'amount' => $amount, 'phone' => OrderMessage::phone($phone)], $merchant->locale ?? 'en');

        foreach ($messenger->staff($merchant) as $member) {
            $messenger->send($merchant, $member, $body, [], ShopTemplateService::REFUND_NEEDED, [$merchant->name, (string) $order->number, StaffMessenger::oneLine($amount), $phone]);
        }
    }
}
