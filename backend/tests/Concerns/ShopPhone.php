<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Order;
use App\Services\WhatsApp\WhatsAppInboundService;

/** The shop answers by typing in the customer's chat on the shop phone (what the app sees as a business-app echo). */
trait ShopPhone
{
    private int $echoSeq = 0;

    protected function shopTypes(string $customerPhone, string $text, string $phoneId = '555'): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['field' => 'smb_message_echoes', 'value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'message_echoes' => [['from' => '96171000000', 'to' => ltrim($customerPhone, '+'), 'id' => 'wamid.SHOP'.(++$this->echoSeq).uniqid(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    /** What the old staff buttons did, now typed: stf:accept:ID → ✅, stf:wh_ok:ID → ✅, stf:ready:ID → "ready", … */
    protected function shopTap(string $id): void
    {
        [, $action, $target] = array_pad(explode(':', $id, 3), 3, '');
        $text = match ($action) {
            'accept', 'wh_ok', 'approve' => '✅',
            'reject', 'wh_no', 'refuse' => '❌',
            'ready' => 'ready',
            'picked_up' => 'out',
            'delivered' => 'done',
            'delay' => 'delay',
            default => null,
        };
        if ($text === null) {
            return;
        }
        $order = Order::query()->withoutGlobalScopes()->find(explode(':', $target)[0]);
        $customer = $order?->customer ?? Customer::query()->withoutGlobalScopes()->find(explode(':', $target)[0]);
        $this->shopTypes((string) $customer?->phone, $text);
    }
}
