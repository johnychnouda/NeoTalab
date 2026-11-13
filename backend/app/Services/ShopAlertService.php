<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pings the shop's report WhatsApp for moments that are easy to miss in the customer chat:
 * handoff, photo, staff-check, failed delivery, Whish refund due.
 */
class ShopAlertService
{
    public function __construct(protected WhatsAppService $whatsapp) {}

    public function handoff(Merchant $merchant, Customer $customer, string $reason = 'handoff'): void
    {
        $this->ping($merchant, 'handoff:'.$customer->id, __('whatsapp.shop.alert_handoff', [
            'name' => $customer->name ?: '—',
            'phone' => $this->phone($customer),
            'reason' => $reason,
        ], $merchant->locale ?? 'en'));
    }

    public function photo(Merchant $merchant, Customer $customer): void
    {
        $this->ping($merchant, 'photo:'.$customer->id, __('whatsapp.shop.alert_photo', [
            'name' => $customer->name ?: '—',
            'phone' => $this->phone($customer),
        ], $merchant->locale ?? 'en'));
    }

    public function staffCheck(Merchant $merchant, Customer $customer, Product $product): void
    {
        $this->ping($merchant, 'check:'.$customer->id.':'.$product->id, __('whatsapp.shop.alert_check', [
            'item' => $product->name,
            'name' => $customer->name ?: '—',
            'phone' => $this->phone($customer),
        ], $merchant->locale ?? 'en'));
    }

    public function deliveryFailed(Merchant $merchant, Order $order): void
    {
        $paid = in_array($order->payment_status, ['paid', 'refund_due'], true)
            || ($order->payment_method === 'whish' && $order->payment_status === 'paid');
        $locale = $merchant->locale ?? 'en';
        $params = ['number' => $order->number];
        $body = __('whatsapp.shop.alert_failed', [
            'number' => $order->number,
            'name' => $order->customer?->name ?: '—',
            'phone' => $this->phone($order->customer),
            'hint' => $paid
                ? __('whatsapp.shop.alert_failed_paid', $params, $locale)
                : __('whatsapp.shop.alert_failed_retry', $params, $locale),
        ], $locale);

        $this->ping($merchant, 'failed:'.$order->id, $body, throttleSeconds: 60);
    }

    public function refundDue(Merchant $merchant, Order $order): void
    {
        $this->ping($merchant, 'refund:'.$order->id, __('whatsapp.shop.alert_refund', [
            'number' => $order->number,
            'total' => $order->total,
            'currency' => $order->currency ?: 'USD',
            'name' => $order->customer?->name ?: '—',
            'phone' => $this->phone($order->customer),
        ], $merchant->locale ?? 'en'), throttleSeconds: 60);
    }

    public function lateExhausted(Merchant $merchant, Order $order): void
    {
        $this->ping($merchant, 'late:'.$order->id, __('whatsapp.shop.alert_late', [
            'number' => $order->number,
            'name' => $order->customer?->name ?: '—',
            'phone' => $this->phone($order->customer),
        ], $merchant->locale ?? 'en'), throttleSeconds: 300);
    }

    protected function phone(?Customer $customer): string
    {
        $raw = (string) ($customer?->phone ?? '');

        return $raw !== '' ? $raw : '—';
    }

    protected function ping(Merchant $merchant, string $key, string $body, int $throttleSeconds = 600): void
    {
        $to = ShopSettings::for($merchant)->reportWhatsapp();
        if ($to === '' || ! MerchantWhatsAppCredentials::resolve($merchant)) {
            return;
        }

        if (! Cache::add("shop-alert:{$merchant->id}:{$key}", true, $throttleSeconds)) {
            return;
        }

        try {
            $this->whatsapp->sendToCustomer($merchant, $to, ['type' => 'text', 'text' => mb_substr($body, 0, 4096)]);
        } catch (\Throwable $e) {
            Log::info('Shop alert not delivered (check report WhatsApp / 24h window)', [
                'merchant_id' => $merchant->id,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
