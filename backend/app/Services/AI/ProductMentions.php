<?php

namespace App\Services\AI;

/**
 * Finds product names inside a customer message. A name that sits inside a longer
 * product name ("Falafel" inside "Falafel Plate") does not count, so the shorter
 * product is never picked by mistake.
 */
final class ProductMentions
{
    /**
     * Lower case, Arabic-Indic digits as Western digits, single spaces.
     */
    public static function normalise(string $text): string
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /**
     * True when any of the given names appears in the text as a product mention.
     *
     * @param  array<int, string>  $names  normalised names of one product
     * @param  array<int, string>  $allNames  normalised names of every product in the catalog
     */
    public static function mentions(string $text, array $names, array $allNames): bool
    {
        foreach ($names as $name) {
            if (self::occurrences($text, $name, $allNames) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Byte spans [start, end] where the name appears as a whole word (plural s/es allowed),
     * leaving out spans that sit inside a longer product name.
     *
     * @param  array<int, string>  $allNames
     * @return array<int, array{0:int,1:int}>
     */
    public static function occurrences(string $text, string $name, array $allNames): array
    {
        $kept = [];

        foreach (self::spans($text, $name) as [$start, $end]) {
            if (! self::insideLongerName($text, $start, $end, $name, $allNames)) {
                $kept[] = [$start, $end];
            }
        }

        return $kept;
    }

    /**
     * @return array<int, array{0:int,1:int}>
     */
    private static function spans(string $text, string $name): array
    {
        if ($name === '') {
            return [];
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?:s|es)?(?![\p{L}\p{N}])/u';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $m) => [$m[1], $m[1] + strlen($m[0])], $matches[0]);
    }

    /**
     * @param  array<int, string>  $allNames
     */
    private static function insideLongerName(string $text, int $start, int $end, string $name, array $allNames): bool
    {
        foreach ($allNames as $longer) {
            if (strlen($longer) <= strlen($name) || ! str_contains($longer, $name)) {
                continue;
            }

            foreach (self::spans($text, $longer) as [$longStart, $longEnd]) {
                if ($longStart <= $start && $longEnd >= $end) {
                    return true;
                }
            }
        }

        return false;
    }
}
