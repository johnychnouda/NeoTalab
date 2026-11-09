<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\OrderService;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The shop has not answered: first remind the shop (the reminder shows in the customer's chat on the shop phone), then tell the customer politely
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
            $this->remind($merchant, $order);

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

    /** "Order #1 is still waiting for the shop": the customer is kept informed, and the shop sees it on its phone. */
    private function remind($merchant, $order): void
    {
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';
        try {
            $this->sendAs($merchant, (string) $order->customer?->phone, __('whatsapp.checkout.still_waiting', ['number' => $order->number, 'shop' => $merchant->name], $locale));
        } catch (\Throwable $e) {
            Log::info('Waiting reminder not delivered', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
