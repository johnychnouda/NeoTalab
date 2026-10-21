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
            'total' => Money::format((float) $order->total, $merchant->currency),
            'currency' => (string) $merchant->currency,
            'payment' => __($order->payment_method === 'whish' ? 'whatsapp.staff.pay_whish' : 'whatsapp.staff.pay_cash', [], $locale),
            'items' => $order->items
                ->map(fn ($i) => "• {$i->quantity}× {$i->product_name}".($i->modifiers ? ' ('.implode(', ', $i->modifiers).')' : ''))
                ->implode("\n"),
            'address' => self::addressWithPin($order),
            'phone' => self::phone($order->customer?->phone),
            'collect' => self::paymentLine($order, $merchant),
            'lbp' => $order->total_lbp
                ? __('whatsapp.checkout.lbp', ['amount' => number_format((float) $order->total_lbp)], $locale)
                : '',
        ];
    }

    /** The delivery address as text only (zone and what the customer typed), no link. */
    public static function addressText(Order $order): string
    {
        return trim(($order->delivery_zone_name ? "{$order->delivery_zone_name} — " : '').($order->delivery_address ?? ''), ' —') ?: '📍';
    }

    /**
     * The delivery address, plus the customer's pin as a Google Maps link when they sent one. Shops
     * and drivers get the link; customers never do.
     */
    public static function addressWithPin(Order $order): string
    {
        $address = self::addressText($order);
        if ($order->fulfillment !== 'delivery' || $order->delivery_lat === null || $order->delivery_lng === null) {
            return $address;
        }

        return $address."\n📍 https://www.google.com/maps?q={$order->delivery_lat},{$order->delivery_lng}";
    }

    /**
     * A phone number kept left to right inside Arabic text, where "+961 71 234 567" would
     * otherwise show as "567 234 71 961+". The marks are invisible.
     */
    public static function phone(?string $phone): string
    {
        return filled($phone) ? "\u{2066}{$phone}\u{2069}" : '';
    }

    /**
     * The same values for a driver's message, where :collect is a whole line: the cash to collect,
     * or that the order is already paid with Whish so the driver takes nothing from the customer.
     *
     * @return array<string, string>
     */
    public static function forDriver(Order $order, Merchant $merchant, ?string $locale = null): array
    {
        $locale ??= $merchant->locale ?? 'en';
        $params = self::params($order, $merchant, $locale);
        $params['collect'] = $order->payment_method === 'whish'
            ? __('whatsapp.driver.paid_whish', [], $locale)
            : __('whatsapp.driver.collect_cash', ['amount' => $params['collect']], $locale);

        return $params;
    }

    /**
     * What the customer pays / the driver collects: "12.00$", "1,074,000 LBP" or "5.00$ + 627,000 LBP".
     */
    public static function paymentLine(Order $order, Merchant $merchant): string
    {
        $main = fn (float $v) => Money::format($v, $merchant->currency);
        $lbp = fn ($v) => Money::lbp((float) $v);

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
