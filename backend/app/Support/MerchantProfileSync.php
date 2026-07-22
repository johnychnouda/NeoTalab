<?php

namespace App\Support;

use App\Models\Merchant;

/**
 * Keeps merchant profile/contact fields aligned between the owner portal and merchant backoffice.
 */
final class MerchantProfileSync
{
    /**
     * @param  array<string,mixed>  $settings
     */
    public static function applyWhatsapp(Merchant $merchant, array &$settings, ?string $whatsapp): void
    {
        if ($whatsapp === null) {
            return;
        }

        $whatsapp = trim($whatsapp);
        if ($whatsapp === '') {
            unset($settings['ops_whatsapp']);

            return;
        }

        $merchant->whatsapp_number = $whatsapp;
        $merchant->phone = $whatsapp;
        $settings['ops_whatsapp'] = $whatsapp;
    }

    public static function applyShopName(Merchant $merchant, ?string $name, ?string $nameAr = null): void
    {
        if ($name !== null) {
            $name = trim($name);
            if ($name !== '') {
                $merchant->name = $name;
            }
        }

        if ($nameAr !== null) {
            $merchant->name_ar = $nameAr === '' ? null : $nameAr;
        }
    }

    public static function applyLocale(Merchant $merchant, ?string $locale): void
    {
        if ($locale !== null && $locale !== '') {
            $merchant->locale = $locale;
        }
    }

    /**
     * Canonical WhatsApp for API responses (owner + merchant UIs).
     */
    public static function whatsapp(Merchant $merchant): ?string
    {
        $settings = $merchant->settings ?? [];

        return ($settings['ops_whatsapp'] ?? null)
            ?: ($merchant->whatsapp_number ?? $merchant->phone);
    }
}
