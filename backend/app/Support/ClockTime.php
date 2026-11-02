<?php

namespace App\Support;

/**
 * The time of day a customer wrote in a message ("10:00", "10h30", "٢٠:٣٠", "3 pm", "à 10h"), as "HH:MM".
 * Null when there is none, or when the message names two different times (it is not clear which is meant).
 */
final class ClockTime
{
    public static function fromText(string $text): ?string
    {
        // Arabic-Indic and Persian digits become ordinary digits.
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $found = [];

        // 10:00, 9:30, 10h30
        if (preg_match_all('/(?<!\d)([01]?\d|2[0-3])\s*[:h]\s*([0-5]\d)(?!\d)/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $found[] = sprintf('%02d:%s', (int) $x[1], $x[2]);
            }
        }
        // 10h, à 10 h
        if ($found === [] && preg_match_all('/(?<!\d)([01]?\d|2[0-3])\s*h(?![a-z0-9])/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $found[] = sprintf('%02d:00', (int) $x[1]);
            }
        }
        // 3 pm, 11am
        if ($found === [] && preg_match_all('/(?<!\d)(1[0-2]|0?[1-9])\s*(am|pm)\b/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $hour = ((int) $x[1] % 12) + (strtolower($x[2]) === 'pm' ? 12 : 0);
                $found[] = sprintf('%02d:00', $hour);
            }
        }

        $found = array_values(array_unique($found));

        return count($found) === 1 ? $found[0] : null;
    }
}
