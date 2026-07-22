<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\PlatformSetting;

/**
 * Resolves Meta Cloud API credentials for a merchant.
 *
 * Release model: one platform access token + per-merchant Phone Number ID.
 * Merchants may still store their own access_token (legacy / BYO Meta Business).
 */
final class MerchantWhatsAppCredentials
{
    /**
     * @return array{phone_id:string,access_token:string}|null
     */
    public static function resolve(Merchant $merchant): ?array
    {
        $bot = ($merchant->settings ?? [])['bot'] ?? [];
        $phoneId = trim((string) ($bot['phone_id'] ?? ''));
        if ($phoneId === '') {
            return null;
        }

        $token = trim((string) ($bot['access_token'] ?? ''));
        if ($token === '') {
            $token = self::platformAccessToken();
        }

        if ($token === '') {
            return null;
        }

        return [
            'phone_id' => $phoneId,
            'access_token' => $token,
        ];
    }

    public static function platformAccessToken(): string
    {
        $fromEnv = trim((string) config('whatsapp.platform_access_token', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $platform = PlatformSetting::singleton()->settings ?? [];

        return trim((string) ($platform['wa_access_token'] ?? ''));
    }

    public static function verifyToken(): string
    {
        $platform = PlatformSetting::singleton()->settings ?? [];
        $fromSettings = trim((string) ($platform['wa_verify_token'] ?? ''));

        return $fromSettings !== ''
            ? $fromSettings
            : (string) config('whatsapp.verify_token', 'neotalab-verify');
    }

    public static function findMerchantByPhoneId(string $phoneId): ?Merchant
    {
        $phoneId = trim($phoneId);
        if ($phoneId === '') {
            return null;
        }

        return Merchant::query()
            ->where('status', 'active')
            ->where('settings->bot->phone_id', $phoneId)
            ->first();
    }
}
