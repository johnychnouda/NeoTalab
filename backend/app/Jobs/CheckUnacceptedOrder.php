<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\OrderService;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The shop has not tapped Accept: first remind the staff, then tell the customer politely
 * that the shop could not take the order (a paid Whish order is flagged for refund).
 */
class CheckUnacceptedOrder implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $orderId, public bool $final = false) {}

    public function handle(OrderService $orders): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order || $order->status !== 'placed') {
            return;
        }

        if (! $this->final) {
            NotifyStaffOfOrder::dispatchSync($order->id, true);

            return;
        }

        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';
        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);
        try {
            $orders->transition($merchant, $order->id, 'reject', ['reason' => null, 'silent' => true]);
        } finally {
            $tenant->set($previous);
        }

        try {
            $this->sendAs($merchant, (string) $order->customer?->phone, __('whatsapp.status.not_accepted', [
                'number' => $order->number, 'shop' => $merchant->name,
            ], $locale).($order->fresh()->payment_status === 'refund_due' ? "\n".__('whatsapp.status.refund', ['shop' => $merchant->name], $locale) : ''));
        } catch (\Throwable $e) {
            Log::warning('Unaccepted-order message failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
