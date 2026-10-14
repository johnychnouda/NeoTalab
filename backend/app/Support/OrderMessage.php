<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\Order;

/**
 * Builds the placeholder values shared by order texts sent to shops and drivers.
 */
final class OrderMessage
{
    /**
     * @return array<string, string>
     */
    public static function params(Order $order, Merchant $merchant, ?string $locale = null): array
    {
        $order->loadMissing(['items', 'customer']);
        $locale ??= $merchant->locale ?? 'en';

        return [
            'shop' => $merchant->name,
            'number' => (string) ($order->number ?? strtoupper(substr($order->id, -6))),
            'total' => number_format((float) $order->total, 2),
            'currency' => (string) $merchant->currency,
            'payment' => (string) $order->payment_method,
            'items' => $order->items
                ->map(fn ($i) => "• {$i->quantity}× {$i->product_name}".($i->modifiers ? ' ('.implode(', ', $i->modifiers).')' : ''))
                ->implode("\n"),
            'address' => trim(($order->delivery_zone_name ? "{$order->delivery_zone_name} — " : '').($order->delivery_address ?? ''), ' —') ?: '📍',
            'phone' => (string) $order->customer?->phone,
            'collect' => self::paymentLine($order, $merchant),
            'lbp' => $order->total_lbp
                ? __('whatsapp.checkout.lbp', ['amount' => number_format((float) $order->total_lbp)], $locale)
                : '',
        ];
    }

    /**
     * What the customer pays / the driver collects: "12.00 USD", "1,074,000 LBP" or "5.00 USD + 627,000 LBP".
     */
    public static function paymentLine(Order $order, Merchant $merchant): string
    {
        $main = fn (float $v) => number_format($v, 2).' '.$merchant->currency;
        $lbp = fn ($v) => number_format((float) $v).' LBP';

        return match ($order->payment_currency) {
            'LBP' => $lbp($order->total_lbp),
            'MIXED' => $main((float) $order->cash_main).' + '.$lbp($order->total_lbp),
            default => $main((float) $order->total),
        };
    }

    /**
     * The customer's pin as a WhatsApp location message (opens directions inside WhatsApp),
     * or null when the customer typed their address instead.
     *
     * @return array<string, mixed>|null
     */
    public static function locationMessage(Order $order): ?array
    {
        if ($order->fulfillment !== 'delivery' || $order->delivery_lat === null || $order->delivery_lng === null) {
            return null;
        }

        return ['type' => 'location', 'location' => array_filter([
            'latitude' => (float) $order->delivery_lat,
            'longitude' => (float) $order->delivery_lng,
            'name' => '#'.$order->number,
            'address' => trim(($order->delivery_zone_name ? "{$order->delivery_zone_name} — " : '').($order->delivery_address ?? ''), ' —') ?: null,
        ], fn ($v) => $v !== null)];
    }
}
