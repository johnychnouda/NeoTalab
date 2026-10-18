<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Turns what a shop pastes as its location into "lat,lng": plain numbers, a full Google Maps link,
 * or the short link from Google Maps' Share button (maps.app.goo.gl/…), which holds no location
 * itself, it is followed to Google to find one. Only Google addresses are ever fetched.
 */
class MapLocation
{
    protected const MAX_HOPS = 5;

    /** "lat,lng", or null when no location can be found in it. */
    public static function resolve(string $input): ?string
    {
        $input = trim($input);
        if ($found = self::fromText($input)) {
            return $found;
        }

        $url = preg_match('~https?://\S+~i', $input, $m) ? $m[0] : null;
        for ($hop = 0; $url && $hop < self::MAX_HOPS && self::isGoogle($url); $hop++) {
            try {
                $response = Http::timeout(6)->withoutRedirecting()
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'en'])
                    ->get($url);
            } catch (\Throwable) {
                return null;
            }

            $next = $response->header('Location');
            if (! $next) {
                // The final page: only the place's own pin counts. Its map "center" is the visitor's
                // area (from their connection), not the shop, so it is never used.
                return self::fromText(substr((string) $response->body(), 0, 500_000), pinOnly: true);
            }

            $url = self::absolute($next, $url);
            // Europe: Google first asks for cookie consent; the real address is in "continue".
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_starts_with((string) parse_url($url, PHP_URL_HOST), 'consent.') && is_string($query['continue'] ?? null)) {
                $url = $query['continue'];
            }
            if ($found = self::fromText($url)) {
                return $found;
            }
        }

        return null;
    }

    /** A link that opens this pin in Google Maps, or null when the text has no coordinates. */
    public static function mapsUrl(string $latLng): ?string
    {
        $pin = self::resolve(trim($latLng));

        return $pin ? 'https://www.google.com/maps?q='.str_replace(' ', '', $pin) : null;
    }

    /** Coordinates written in a link or text, most precise first (the place pin, then the map view). */
    protected static function fromText(string $text, bool $pinOnly = false): ?string
    {
        $text = urldecode($text);
        $patterns = $pinOnly ? ['/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/'] : [
            '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',
            '/[?&](?:q|query|ll|destination)=(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/',
            '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',
            '/^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180) {
                return round((float) $m[1], 6).','.round((float) $m[2], 6);
            }
        }

        return null;
    }

    protected static function isGoogle(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && preg_match('/^(maps\.app\.goo\.gl|goo\.gl|(?:[a-z0-9-]+\.)*google\.[a-z]{2,3}(?:\.[a-z]{2})?)$/', $host) === 1;
    }

    protected static function absolute(string $location, string $base): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $parts = parse_url($base);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').'/'.ltrim($location, '/');
    }
}
