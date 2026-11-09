<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use App\Support\OrderMessage;
use App\Support\ShopSettings;

/**
 * Interactive WhatsApp tickets for the shop owner (report_whatsapp), so they can Accept / Ready /
 * Out / Done with buttons. Typing ✅ / ready / done in the customer chat still works.
 */
final class ShopTicket
{
    /**
     * @return array{body: string, buttons: list<array{0: string, 1: string}>}|null
     */
    public function forOrder(Merchant $merchant, Order $order): ?array
    {
        $locale = $merchant->locale ?? 'en';
        $params = OrderMessage::params($order, $merchant, $locale);
        $pickup = $order->fulfillment === 'pickup';

        if ($order->status === 'placed') {
            $time = app(OrderTiming::class)->label($merchant, $locale);

            return [
                'body' => __('whatsapp.shop.ticket_new', $params, $locale),
                'buttons' => [
                    ["stf:accept:{$order->id}", __('whatsapp.shop.btn_accept', ['time' => $time], $locale)],
                    ["stf:reject:{$order->id}", __('whatsapp.shop.btn_reject', [], $locale)],
                ],
            ];
        }

        if ($order->payment_status === 'awaiting_check') {
            return [
                'body' => __('whatsapp.shop.ticket_whish', $params, $locale),
                'buttons' => [
                    ["stf:wh_ok:{$order->id}", __('whatsapp.shop.btn_whish_ok', [], $locale)],
                    ["stf:wh_no:{$order->id}", __('whatsapp.shop.btn_whish_no', [], $locale)],
                ],
            ];
        }

        if (! in_array($order->status, ['confirmed', 'preparing', 'ready', 'assigned', 'picked_up'], true)) {
            return null;
        }

        $body = __('whatsapp.shop.ticket_go', $params + [
            'hint' => $pickup
                ? __('whatsapp.shop.hint_pickup', [], $locale)
                : ($order->driver
                    ? __('whatsapp.shop.hint_driver', ['driver' => $order->driver->name], $locale)
                    : __('whatsapp.shop.hint_delivery', [], $locale)),
        ], $locale);

        $buttons = $pickup
            ? [
                ["stf:ready:{$order->id}", __('whatsapp.shop.btn_ready', [], $locale)],
                ["stf:delivered:{$order->id}", __('whatsapp.shop.btn_collected', [], $locale)],
            ]
            : [
                ["stf:picked_up:{$order->id}", __('whatsapp.shop.btn_out', [], $locale)],
                ["stf:delivered:{$order->id}", __('whatsapp.shop.btn_delivered', [], $locale)],
            ];

        return ['body' => $body, 'buttons' => $buttons];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $buttons
     * @return array<string, mixed>
     */
    public function interactive(string $body, array $buttons): array
    {
        return ['type' => 'interactive', 'interactive' => [
            'type' => 'button',
            'body' => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => array_map(
                fn (array $b) => ['type' => 'reply', 'reply' => ['id' => $b[0], 'title' => mb_substr($b[1], 0, 20)]],
                array_slice($buttons, 0, 3),
            )],
        ]];
    }

    /** Owner ops number for tickets, or empty when unset. */
    public function recipient(Merchant $merchant): string
    {
        return ShopSettings::for($merchant)->reportWhatsapp();
    }
}
