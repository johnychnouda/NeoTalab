<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Models\Driver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tells the driver who had the order that it was cancelled, so he does not pick it up.
 */
class NotifyDriverOfCancellation implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 2;

    public function __construct(public string $orderId, public string $driverId) {}

    public function handle(): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        $driver = $order ? Driver::query()->withoutGlobalScopes()->find($this->driverId) : null;
        $to = $driver?->whatsapp_number ?: $driver?->phone;
        if (! $order || ! $to) {
            return;
        }

        try {
            $this->sendAs($merchant, $to, __('whatsapp.driver.cancelled', ['number' => $order->number], $merchant->locale ?? 'en'));
        } catch (\Throwable $e) {
            Log::warning('Driver cancellation message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
