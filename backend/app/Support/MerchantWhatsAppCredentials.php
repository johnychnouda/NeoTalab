<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Services\WhatsApp\PlatformWhatsAppCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

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

        $token = StoredSecret::open($bot['access_token'] ?? null);
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

    /**
     * Token for sending from NeoTalab's own number: its own token when it was connected with
     * Embedded Signup, otherwise the shared platform token.
     */
    public static function platformSenderToken(): string
    {
        $own = (string) ((PlatformSetting::singleton()->settings ?? [])['wa_phone_access_token'] ?? '');
        if ($own !== '') {
            try {
                return Crypt::decryptString($own);
            } catch (\Throwable) {
                // fall back to the shared token
            }
        }

        return self::platformAccessToken();
    }

    /**
     * NeoTalab's own number as digits ('' when not known). Saved by the QR connection; when its ID was typed in by
     * hand it is asked from Meta once and remembered for a day (never written to the settings, which would make the
     * owner portal think the number was connected with the QR). If Meta does not answer it is asked again in ten minutes.
     */
    public static function platformNumberDigits(): string
    {
        $settings = PlatformSetting::singleton()->settings ?? [];
        $display = (string) ($settings['wa_display_phone'] ?? '');
        $phoneId = trim((string) ($settings['wa_phone_id'] ?? ''));

        if ($display === '' && $phoneId !== '') {
            $key = "wa-platform-number:{$phoneId}";
            $display = (string) Cache::get($key, '');
            if ($display === '' && Cache::add("{$key}:asked", 1, now()->addMinutes(10))) {
                $phone = app(PlatformWhatsAppCheck::class)->run()['phone'] ?? null;
                if (is_string($phone) && $phone !== '') {
                    $display = $phone;
                    Cache::put($key, $phone, now()->addDay());
                }
            }
        }

        return (string) preg_replace('/\D+/', '', $display);
    }

    public static function platformAccessToken(): string
    {
        $fromEnv = trim((string) config('whatsapp.platform_access_token', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $platform = PlatformSetting::singleton()->settings ?? [];

        return StoredSecret::open($platform['wa_access_token'] ?? null);
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
