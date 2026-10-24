<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\DriverDispatcher;
use App\Services\OrderConfirmation;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\CurrentTenant;
use App\Support\DeliverySchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * An order for a chosen time (flowers for Friday 10:00–12:00), run when it is time to prepare it:
 * the staff are reminded, a driver is assigned, and the late check starts.
 */
class StartScheduledOrder implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $orderId) {}

    public function handle(OrderConfirmation $confirmation, StaffMessenger $messenger): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order || ! $order->deliver_at || ! in_array($order->status, ['confirmed', 'preparing', 'ready'], true)) {
            return;
        }

        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);
        try {
            $locale = $merchant->locale ?? 'en';
            $body = __('whatsapp.staff.scheduled_due', ['number' => $order->number, 'when' => DeliverySchedule::label($merchant, $order->deliver_at, $locale)], $locale);
            foreach ($messenger->staff($merchant) as $member) {
                $messenger->send($merchant, $member, $body, []);
            }

            if ($order->fulfillment === 'delivery' && ! $order->driver_id) {
                app(DriverDispatcher::class)->assign($merchant, $order);
            }
            $confirmation->lateCheck($merchant, $order->fresh());
        } finally {
            $tenant->set($previous);
        }
    }
}
