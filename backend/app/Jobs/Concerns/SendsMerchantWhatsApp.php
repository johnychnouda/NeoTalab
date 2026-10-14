<?php

namespace App\Jobs\Concerns;

use App\Models\Merchant;
use App\Models\Order;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\CurrentTenant;
use App\Support\MerchantWhatsAppCredentials;

/**
 * Shared plumbing for order-related WhatsApp jobs: load the order across tenants, bind the
 * merchant while sending, and restore the previous tenant (sync queue runs inside requests).
 */
trait SendsMerchantWhatsApp
{
    /**
     * @return array{0: Order, 1: Merchant}|null null when there is nothing to send with
     */
    protected function loadOrder(string $orderId): ?array
    {
        $order = (new Order)->newQueryWithoutTenancy()->with(['customer', 'driver', 'items'])->find($orderId);
        $merchant = $order ? Merchant::find($order->merchant_id) : null;

        if (! $order || ! $merchant || ! MerchantWhatsAppCredentials::resolve($merchant)) {
            return null;
        }

        return [$order, $merchant];
    }

    protected function sendAs(Merchant $merchant, string $to, string $text): void
    {
        $this->sendMessageAs($merchant, $to, ['type' => 'text', 'text' => $text]);
    }

    /**
     * @param  array<string, mixed>  $message  WhatsAppService message (text / interactive / template)
     */
    protected function sendMessageAs(Merchant $merchant, string $to, array $message): void
    {
        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);

        try {
            app(WhatsAppService::class)->sendToCustomer($merchant, $to, $message);
        } finally {
            $tenant->set($previous);
        }
    }
}
