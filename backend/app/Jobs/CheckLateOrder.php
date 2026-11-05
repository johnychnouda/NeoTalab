<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\OrderTiming;
use App\Services\TravelTime;
use App\Services\WhatsApp\CustomerTemplates;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Food shops: runs just after the time the customer was told the order would be ready (or leave the shop). If it has not
 * left yet, the customer gets a new, realistic time (and this check runs again then), up to OrderTiming::MAX_UPDATES.
 * Once the order is on its way the customer keeps the arrival time they were given: nothing more is sent.
 */
class CheckLateOrder implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $orderId) {}

    public function handle(OrderTiming $timing): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order || ! $order->customer?->phone || ! $order->promised_at
            || ! in_array($order->status, ['confirmed', 'preparing', 'ready', 'assigned'], true)
            || $order->eta_updates >= OrderTiming::MAX_UPDATES || $timing->dueAt($order)->isFuture()) {
            return;
        }

        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);
        try {
            // Still at the shop: a bit more cooking, then the drive.
            $new = now()->addMinutes(OrderTiming::SIGNIFICANT_MINS + ($order->fulfillment === 'delivery' ? app(TravelTime::class)->minutes($merchant, $order) : 0));
        } finally {
            $tenant->set($previous);
        }

        $order->forceFill(['promised_at' => $new, 'eta_updates' => $order->eta_updates + 1, 'late_notified_at' => now()])->save();
        $locale = $order->customer->locale ?? $merchant->locale ?? 'en';

        try {
            $when = $timing->phrase($merchant, $order, $locale);
            $this->sendCustomerAs(
                $merchant, $order->customer->phone,
                ['type' => 'text', 'text' => __('whatsapp.status.late', ['number' => $order->number, 'shop' => $merchant->name, 'when' => $when], $locale)],
                CustomerTemplates::DELAYED, [(string) $order->number, $merchant->name, $when], $locale,
            );
        } catch (\Throwable $e) {
            Log::warning('Late-order message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        self::dispatch($order->id)->delay($timing->dueAt($order)->addMinutes(2));
    }
}
