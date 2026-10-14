<?php

namespace App\Jobs;

use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\OrderConfirmation;
use App\Services\OrderTiming;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\CurrentTenant;
use App\Support\OrderMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends every new order to the shop's staff numbers with the buttons that close it:
 * pickup → Ready / Collected, delivery → Out for delivery / Delivered (for shops that deliver
 * themselves; drivers still get their own assignment message).
 *
 * An order waiting for the shop goes as Accept / Can't take it, as the approved template to
 * staff who haven't written in 24 hours. If no staff member can be reached at all, the bot
 * accepts the order itself instead of letting it be refused for nobody answering.
 */
class NotifyStaffOfOrder implements ShouldQueue
{
    use Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $orderId, public bool $reminder = false) {}

    public function handle(StaffMessenger $messenger): void
    {
        [$order, $merchant] = $this->loadOrder($this->orderId) ?? [null, null];
        if (! $order) {
            return;
        }

        $locale = $merchant->locale ?? 'en';
        $pickup = $order->fulfillment === 'pickup';
        $params = OrderMessage::params($order, $merchant, $locale);

        $body = __($pickup ? 'whatsapp.staff.new_order_pickup' : 'whatsapp.staff.new_order_delivery', $params, $locale);

        $buttons = $pickup
            ? [['stf:ready', __('whatsapp.staff.button_ready', [], $locale)], ['stf:delivered', __('whatsapp.staff.button_collected', [], $locale)]]
            : [['stf:picked_up', __('whatsapp.staff.button_out', [], $locale)], ['stf:delivered', __('whatsapp.staff.button_delivered', [], $locale)]];
        $template = null;
        $templateParams = [];

        // Waiting for the shop: Accept (with the shop's usual time) or Can't take it.
        $waiting = $order->status === 'placed';
        if ($waiting) {
            if ($this->reminder) {
                $body = __('whatsapp.staff.still_waiting', ['number' => $order->number], $locale)."\n".$body;
            }
            $time = app(OrderTiming::class)->label($merchant, $locale);
            $buttons = [
                ['stf:accept', __('whatsapp.staff.button_accept', ['time' => $time], $locale)],
                ['stf:reject', __('whatsapp.staff.button_reject', [], $locale)],
            ];
            $template = ShopTemplateService::NEW_ORDER;
            // The ticket without its first line ("🔔 New order #12 — Shop"), which the template already says.
            $details = implode("\n", array_slice(explode("\n", $body), $this->reminder ? 2 : 1));
            $templateParams = [(string) $order->number, $merchant->name, StaffMessenger::oneLine($details), $time];
        } elseif ($this->reminder) {
            return;
        } else {
            // Accepted (and paid, for Whish): the ticket says it is time to prepare, and who delivers.
            $paid = $order->payment_method === 'whish' && $order->payment_status === 'paid';
            $lines = explode("\n", $body);
            $lines[0] = __($paid ? 'whatsapp.staff.paid_go' : 'whatsapp.staff.confirmed_go', ['number' => $params['number']], $locale);
            if ($pickup) {
                $lines[] = __('whatsapp.staff.pickup_note', [], $locale);
            } elseif ($order->driver) {
                $lines[] = __('whatsapp.staff.driver_takes', ['driver' => $order->driver->name], $locale);
            }
            $body = implode("\n", $lines);
        }

        $buttons = array_map(fn ($b) => ["{$b[0]}:{$order->id}", $b[1]], $buttons);

        $reached = false;
        foreach ($messenger->staff($merchant) as $member) {
            $reached = $messenger->send($merchant, $member, $body, $buttons, $template, $templateParams) || $reached;
        }

        // A Whish order is accepted from the shop phone (✅ in the customer's chat) when no staff
        // member got the ticket: the reminder goes to that chat, where the shop sees it.
        if ($waiting && ! $reached && $order->payment_method === 'whish') {
            if ($this->reminder) {
                $this->remindInCustomerChat($merchant, $order);
            }

            return;
        }

        if ($waiting && ! $reached && ! $this->reminder) {
            Log::warning('No staff member could get the order ticket — the bot accepted the order', [
                'merchant_id' => $merchant->id, 'order_id' => $order->id,
            ]);
            $this->acceptForTheShop($merchant, $order);
        }
    }

    private function remindInCustomerChat($merchant, $order): void
    {
        $locale = $order->customer?->locale ?? $merchant->locale ?? 'en';
        try {
            $this->sendAs($merchant, (string) $order->customer?->phone,
                __('whatsapp.checkout.still_waiting', ['number' => $order->number, 'shop' => $merchant->name], $locale)
                ."\n".__('whatsapp.checkout.shop_reply', ['shop' => $merchant->name], $locale));
        } catch (\Throwable $e) {
            Log::info('Waiting reminder not delivered', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function acceptForTheShop($merchant, $order): void
    {
        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set($merchant);
        try {
            app(OrderConfirmation::class)->accept($merchant, $order);
        } finally {
            $tenant->set($previous);
        }
    }
}
