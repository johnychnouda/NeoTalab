<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\OrderTiming;
use App\Services\TravelTime;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Food shops: runs just after the promised time. If the order is not delivered yet, the customer
 * gets a new, realistic time (and this check runs again then), up to OrderTiming::MAX_UPDATES.
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
            || ! in_array($order->status, ['confirmed', 'preparing', 'ready', 'assigned', 'picked_up'], true)
            || $order->eta_updates >= OrderTiming::MAX_UPDATES || $order->promised_at->isFuture()) {
            return;
        }

        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);
        try {
            // On the way: a few more minutes. Still at the shop: a bit more cooking, then the drive.
            $new = $order->status === 'picked_up'
                ? now()->addMinutes(OrderTiming::SIGNIFICANT_MINS)
                : now()->addMinutes(OrderTiming::SIGNIFICANT_MINS + ($order->fulfillment === 'delivery' ? app(TravelTime::class)->minutes($merchant, $order) : 0));
        } finally {
            $tenant->set($previous);
        }

        $order->forceFill(['promised_at' => $new, 'eta_updates' => $order->eta_updates + 1, 'late_notified_at' => now()])->save();
        $locale = $order->customer->locale ?? $merchant->locale ?? 'en';

        try {
            $this->sendAs($merchant, $order->customer->phone, __('whatsapp.status.late', [
                'number' => $order->number,
                'shop' => $merchant->name,
                'when' => $timing->phrase($merchant, $order, $locale),
            ], $locale));
        } catch (\Throwable $e) {
            Log::warning('Late-order message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        self::dispatch($order->id)->delay($new->copy()->addMinutes(2));
    }
}
