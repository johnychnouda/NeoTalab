<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\WhatsAppMessage;

/** WhatsApp's 24-hour window: a number that wrote to the shop number recently can be answered with free text. */
final class WhatsAppWindow
{
    /** A little under Meta's 24 hours, so a message is never sent just as the window closes. */
    public const MINS = 23 * 60 + 30;

    /** True when one of these numbers wrote to the shop number inside the 24-hour window. */
    public static function numberInWindow(Merchant $merchant, ?string ...$numbers): bool
    {
        $numbers = collect($numbers)
            ->map(fn ($n) => preg_replace('/\D+/', '', (string) $n))
            ->filter()->unique()->map(fn ($d) => "+{$d}")->values()->all();

        if ($numbers === []) {
            return false;
        }

        return (new WhatsAppMessage)->newQueryWithoutTenancy()
            ->where('merchant_id', $merchant->id)
            ->where('direction', 'inbound')
            ->whereIn('from_number', $numbers)
            ->where('created_at', '>=', now()->subMinutes(self::MINS))
            ->exists();
    }

    /** Template parameters cannot hold line breaks or long runs of spaces. */
    public static function oneLine(string $text, int $max = 700): string
    {
        $text = preg_replace('/\s*\n+\s*/u', ' · ', trim($text));
        $text = preg_replace('/ {4,}/u', '   ', (string) $text);

        return mb_substr((string) $text, 0, $max);
    }
}
