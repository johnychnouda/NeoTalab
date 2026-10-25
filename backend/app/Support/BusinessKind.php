<?php

namespace App\Support;

use App\Models\Merchant;

/**
 * What kind of business a shop is, and what that changes: the word customers see for the
 * menu, the options suggested for items, units, and the default way of counting time.
 */
final class BusinessKind
{
    /** kind => [menu word, timing unit, business mode, suggested option groups, units]; sizes have their own section, never an option group. */
    public const KINDS = [
        'restaurant' => ['menu', 'minutes', 'orders', ['Extras'], []],
        'grocery' => ['products', 'minutes', 'orders', [], ['piece', 'kg', 'pack']],
        'retail' => ['catalogue', 'days', 'orders', ['Color'], []],
        'flowers' => ['products', 'hours', 'orders', ['Color'], []],
        'water' => ['products', 'hours', 'orders', [], ['gallon', 'cylinder', 'pack']],
        'laundry' => ['price_list', 'days', 'orders', ['Service'], ['piece', 'kg']],
        'services' => ['services', 'minutes', 'appointments', [], []],
        'other' => ['products', 'minutes', 'orders', [], []],
    ];

    /** Business types offered at sign-up (stored in merchants.business_type) and their kind. */
    public const TYPES = [
        'Restaurant / Café' => 'restaurant',
        'Bakery / Sweets' => 'restaurant',
        'Supermarket / Grocery' => 'grocery',
        'Butcher / Fruits & Vegetables' => 'grocery',
        'Clothing / Shoes' => 'retail',
        'Boutique / Clothing' => 'retail',
        'Electronics / Phones' => 'retail',
        'Electronics' => 'retail',
        'Flowers / Gifts' => 'flowers',
        'Flower Shop' => 'flowers',
        'Water / Gas delivery' => 'water',
        'Laundry / Dry cleaning' => 'laundry',
        'Salon / Barber / Beauty' => 'services',
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

    /**
     * Medical businesses are not on the platform (no clinics, dentists, doctors or pharmacies).
     * New sign-ups with these types are refused by the server, not only hidden on the website.
     */
    public const BLOCKED_TYPES = ['Pharmacy', 'Clinic / Dentist'];

    /** A validation rule: the type must not be a medical business. */
    public static function notBlocked(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && self::blocked($value)) {
                $fail('Medical businesses (clinics, dentists, doctors, pharmacies) are not supported on NeoTalab.');
            }
        };
    }

    public static function blocked(string $type): bool
    {
        return in_array(mb_strtolower(trim($type)), array_map('mb_strtolower', self::BLOCKED_TYPES), true)
            || in_array(mb_strtolower(trim($type)), ['pharmacy', 'clinic', 'clinic / dentist', 'dentist', 'doctor', 'pharmacie', 'clinique'], true);
    }

    /** Shops that quote a price for each job: repairs and custom work (laundry, tailoring, repairs). */
    public static function quotes(Merchant $merchant): bool
    {
        // Laundry and any shop that switched it on in its settings (repairs, tailoring, custom work).
        return (bool) (($merchant->settings ?? [])['quotes'] ?? false)
            || (string) $merchant->business_type === 'Laundry / Dry cleaning';
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
        // Flowers and gifts: a delivery time and a recipient by default (the shop can switch them off).
        if ($kind === 'flowers') {
            $settings['scheduled_orders'] ??= true;
            $settings['gift_orders'] ??= true;
        }

        return $settings;
    }

    /** @return array{word: string, option_suggestions: list<string>, units: list<string>} */
    public static function profile(Merchant $merchant): array
    {
        [$word, , , $options, $units] = self::KINDS[self::of($merchant)];

        return ['word' => $word, 'option_suggestions' => $options, 'units' => $units];
    }
}
