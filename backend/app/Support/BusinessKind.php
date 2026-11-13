<?php

namespace App\Support;

use App\Models\Merchant;

/**
 * What kind of business a shop is, and what that changes: the word customers see for the
 * menu, the options suggested for items, units, and the default way of counting time.
 * Each sign-up type has its own kind — nothing is shared as “the same as” another type.
 */
final class BusinessKind
{
    /** kind => [menu word, timing unit, suggested option groups, units]; sizes have their own section, never an option group. */
    public const KINDS = [
        'restaurant' => ['menu', 'minutes', ['Extras'], []],
        'bakery' => ['pastries', 'minutes', ['Topping', 'Filling'], ['piece', 'box']],
        'grocery' => ['products', 'minutes', [], ['piece', 'kg', 'pack']],
        'butcher' => ['fresh', 'minutes', ['Cut'], ['kg', 'piece']],
        'retail' => ['catalogue', 'days', ['Color', 'Size'], []],
        'other' => ['products', 'minutes', [], []],
    ];

    /** Business types offered at sign-up (stored in merchants.business_type) and their kind — one type, one kind. */
    public const TYPES = [
        'Restaurant / Café' => 'restaurant',
        'Bakery / Sweets' => 'bakery',
        'Supermarket / Grocery' => 'grocery',
        'Butcher / Fruits & Vegetables' => 'butcher',
        'Online Shop / Retail' => 'retail',
    ];

    public static function of(Merchant $merchant): string
    {
        $set = ($merchant->settings ?? [])['business_kind'] ?? null;
        if (is_string($set) && isset(self::KINDS[$set])) {
            return $set;
        }

        return self::TYPES[(string) $merchant->business_type] ?? 'other';
    }

    /**
     * Medical businesses are not on the platform (no clinics, dentists, doctors or pharmacies).
     * New sign-ups with these types are refused by the server, not only hidden on the website.
     */
    public const BLOCKED_TYPES = ['Pharmacy', 'Clinic / Dentist', 'Water / Gas delivery', 'Laundry / Dry cleaning', 'Other', 'Clothing / Shoes', 'Electronics / Phones', 'Flowers / Gifts', 'Salon / Barber / Beauty', 'Pet Shop', 'Home & Furniture', 'General Retail'];

    /** A validation rule: the type must not be one NeoTalab does not support. */
    public static function notBlocked(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && self::blocked($value)) {
                $fail('This type of business is not supported on NeoTalab.');
            }
        };
    }

    public static function blocked(string $type): bool
    {
        return in_array(mb_strtolower(trim($type)), array_map('mb_strtolower', self::BLOCKED_TYPES), true)
            || in_array(mb_strtolower(trim($type)), ['pharmacy', 'clinic', 'clinic / dentist', 'dentist', 'doctor', 'pharmacie', 'clinique'], true);
    }

    /**
     * Defaults written to merchants.settings when the kind is first set or changed.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function applyDefaults(array $settings, string $kind): array
    {
        [, $timing] = self::KINDS[$kind] ?? self::KINDS['other'];
        $settings['business_kind'] = $kind;
        $settings['timing_unit'] = $timing;

        return $settings;
    }

    /** @return array{word: string, option_suggestions: list<string>, units: list<string>} */
    public static function profile(Merchant $merchant): array
    {
        [$word, , $options, $units] = self::KINDS[self::of($merchant)] ?? self::KINDS['other'];

        return ['word' => $word, 'option_suggestions' => $options, 'units' => $units];
    }
}
