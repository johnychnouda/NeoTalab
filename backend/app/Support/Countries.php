<?php

namespace App\Support;

/**
 * Countries NeoTalab knows (only ENABLED ones accept new shops on /join), with what a new shop gets on
 * approval: its time zone (opening hours, reports) and the currency of its prices. Lebanon
 * prices in US dollars, like its shops do. Keep in sync with web/lib/countries.js.
 */
final class Countries
{
    /** Where NeoTalab is open for new shops right now (2026-10: Lebanon only). Add a code to open a country. */
    public const ENABLED = ['LB'];

    /** @var array<string, array{timezone: string, currency: string, dial: string}> */
    public const ALL = [
        'LB' => ['timezone' => 'Asia/Beirut', 'currency' => 'USD', 'dial' => '961'],
        'AE' => ['timezone' => 'Asia/Dubai', 'currency' => 'AED', 'dial' => '971'],
        'SA' => ['timezone' => 'Asia/Riyadh', 'currency' => 'SAR', 'dial' => '966'],
        'QA' => ['timezone' => 'Asia/Qatar', 'currency' => 'QAR', 'dial' => '974'],
        'KW' => ['timezone' => 'Asia/Kuwait', 'currency' => 'KWD', 'dial' => '965'],
        'BH' => ['timezone' => 'Asia/Bahrain', 'currency' => 'BHD', 'dial' => '973'],
        'OM' => ['timezone' => 'Asia/Muscat', 'currency' => 'OMR', 'dial' => '968'],
        'JO' => ['timezone' => 'Asia/Amman', 'currency' => 'JOD', 'dial' => '962'],
        'IQ' => ['timezone' => 'Asia/Baghdad', 'currency' => 'IQD', 'dial' => '964'],
        'EG' => ['timezone' => 'Africa/Cairo', 'currency' => 'EGP', 'dial' => '20'],
        'CY' => ['timezone' => 'Asia/Nicosia', 'currency' => 'EUR', 'dial' => '357'],
        'FR' => ['timezone' => 'Europe/Paris', 'currency' => 'EUR', 'dial' => '33'],
    ];

    /** @return list<string> the countries shops can apply from */
    public static function codes(): array
    {
        return array_values(array_intersect(self::ENABLED, array_keys(self::ALL)));
    }

    public static function timezone(?string $code): string
    {
        return self::ALL[strtoupper((string) $code)]['timezone'] ?? 'Asia/Beirut';
    }

    public static function currency(?string $code): string
    {
        return self::ALL[strtoupper((string) $code)]['currency'] ?? 'USD';
    }

    /**
     * A number as a shop types it ("70 123 456", "03 123456", "00961 70…", "+961 70…") in the
     * international form WhatsApp uses ("+96170123456"). Null when it can't be a phone number.
     */
    public static function toE164(?string $raw, ?string $code = 'LB'): ?string
    {
        $raw = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $raw);
        $dial = self::ALL[strtoupper((string) $code)]['dial'] ?? '961';

        if (str_starts_with($raw, '+')) {
            // already international
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! str_starts_with($digits, $dial) || strlen($digits) <= 8) {
            $digits = $dial.ltrim($digits, '0'); // a local number: "03 123456" → 9613123456
        }

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    /** True when the E.164 number starts with the country's dialling code. */
    public static function numberMatches(?string $code, string $e164): bool
    {
        $dial = self::ALL[strtoupper((string) $code)]['dial'] ?? null;

        return $dial !== null && str_starts_with(ltrim($e164, '+'), $dial);
    }
}
