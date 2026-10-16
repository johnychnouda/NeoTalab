<?php

namespace App\Support;

use App\Models\Merchant;

/**
 * What kind of business a shop is, and what that changes: the word customers see for the
 * menu, the options suggested for items, units, and the default way of counting time.
 */
final class BusinessKind
{
    /** kind => [menu word, timing unit, business mode, suggested option groups, units] */
    public const KINDS = [
        'restaurant' => ['menu', 'minutes', 'orders', ['Size', 'Extras'], []],
        'grocery' => ['products', 'minutes', 'orders', ['Size'], ['piece', 'kg', 'pack']],
        'pharmacy' => ['products', 'minutes', 'orders', ['Size'], ['piece', 'box', 'pack']],
        'retail' => ['catalogue', 'days', 'orders', ['Size', 'Color'], []],
        'flowers' => ['products', 'hours', 'orders', ['Size', 'Color'], []],
        'water' => ['products', 'hours', 'orders', [], ['gallon', 'cylinder', 'pack']],
        'laundry' => ['price_list', 'days', 'orders', ['Service'], ['piece', 'kg']],
        'services' => ['services', 'minutes', 'appointments', [], []],
        'other' => ['products', 'minutes', 'orders', ['Size'], []],
    ];

    /** Business types offered at sign-up (stored in merchants.business_type) and their kind. */
    public const TYPES = [
        'Restaurant / Café' => 'restaurant',
        'Bakery / Sweets' => 'restaurant',
        'Supermarket / Grocery' => 'grocery',
        'Butcher / Fruits & Vegetables' => 'grocery',
        'Pharmacy' => 'pharmacy',
        'Clothing / Shoes' => 'retail',
        'Boutique / Clothing' => 'retail',
        'Electronics / Phones' => 'retail',
        'Electronics' => 'retail',
        'Flowers / Gifts' => 'flowers',
        'Flower Shop' => 'flowers',
        'Water / Gas delivery' => 'water',
        'Laundry / Dry cleaning' => 'laundry',
        'Salon / Barber / Beauty' => 'services',
        'Clinic / Dentist' => 'services',
        'Pet Shop' => 'retail',
        'Home & Furniture' => 'retail',
        'General Retail' => 'retail',
        'Other' => 'other',
    ];

    public static function of(Merchant $merchant): string
    {
        $set = ($merchant->settings ?? [])['business_kind'] ?? null;
        if (is_string($set) && isset(self::KINDS[$set])) {
            return $set;
        }

        return self::TYPES[(string) $merchant->business_type] ?? 'other';
    }

    public static function menuWord(Merchant $merchant): string
    {
        return self::KINDS[self::of($merchant)][0];
    }

    /**
     * Defaults written to merchants.settings when the kind is first set or changed.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function applyDefaults(array $settings, string $kind): array
    {
        [, $timing, $mode] = self::KINDS[$kind] ?? self::KINDS['other'];
        $settings['business_kind'] = $kind;
        $settings['timing_unit'] = $timing;
        $settings['business_mode'] = $mode;

        return $settings;
    }

    /** @return array{word: string, option_suggestions: list<string>, units: list<string>} */
    public static function profile(Merchant $merchant): array
    {
        [$word, , , $options, $units] = self::KINDS[self::of($merchant)];

        return ['word' => $word, 'option_suggestions' => $options, 'units' => $units];
    }
}
