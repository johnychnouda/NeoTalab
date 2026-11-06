<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Reads a day and a time a customer wrote ("tomorrow at 6", "demain à 18h30", "بكرا الساعة 6 مساء",
 * "bokra el sa3a 7") in English, French, Arabic or Arabizi. It only reads: whether the shop can do that
 * time is decided by the schedule. A hour from 1 to 12 with no am/pm is "ambiguous": the schedule tries
 * both the morning and the evening.
 */
final class TimeRequest
{
    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '’' => ' ', "'" => ' ', '،' => ' ', ',' => ' '];

    private const PM = '/(?<![a-z])(?:pm|p\.m\.?|evening|tonight|night|afternoon|soir)(?![a-z])|ce soir|apres midi|après midi|du soir|مساء|المسا|ليلا|ليلاً|بالليل|الليلة|العصر|بعد الظهر|el masa|bel leil|bil leil|masa2/u';

    private const AM = '/(?<![a-z])(?:morning|matin)(?![a-z])|du matin|الصبح|صباحا|صباحاً|صباح|el sbeh|bel sabeh|(?<![a-z])sbeh(?![a-z])/u';

    private const WEEKDAYS = [
        1 => '/(?<![a-z])(?:monday|mon)(?![a-z])|lundi|الاثنين|الإثنين|الأثنين/u',
        2 => '/(?<![a-z])(?:tuesday|tues|tue)(?![a-z])|mardi|الثلاثاء|الثلاثا/u',
        3 => '/(?<![a-z])(?:wednesday|wed)(?![a-z])|mercredi|الاربعاء|الأربعاء|الاربعا/u',
        4 => '/(?<![a-z])(?:thursday|thurs|thur|thu)(?![a-z])|jeudi|الخميس/u',
        5 => '/(?<![a-z])(?:friday|fri)(?![a-z])|vendredi|الجمعة|الجمعه/u',
        6 => '/(?<![a-z])(?:saturday|sat)(?![a-z])|samedi|السبت/u',
        0 => '/(?<![a-z])(?:sunday|sun)(?![a-z])|dimanche|الاحد|الأحد/u',
    ];

    /**
     * @return array{date: ?string, hour: ?int, minute: int, ambiguous: bool}|null date as Y-m-d in the shop's time zone
     */
    public static function parse(string $text, Carbon $now): ?array
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 90) {
            return null;
        }
        $t = preg_replace('/\s+/u', ' ', strtr($t, self::DIGITS));

        $date = self::date($t, $now);
        [$hour, $minute, $meridiem] = self::time($t);
        if ($date === null && $hour === null) {
            return null;
        }
        if ($hour === null) {
            return ['date' => $date, 'hour' => null, 'minute' => 0, 'ambiguous' => false];
        }

        if ($meridiem === null) {
            $meridiem = preg_match(self::PM, $t) ? 'pm' : (preg_match(self::AM, $t) ? 'am' : null);
        }
        $ambiguous = false;
        if ($hour >= 1 && $hour <= 12) {
            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            } elseif ($meridiem === null) {
                $ambiguous = true;
            }
        }

        return ['date' => $date, 'hour' => $hour, 'minute' => $minute, 'ambiguous' => $ambiguous];
    }

    private static function date(string $t, Carbon $now): ?string
    {
        $day = $now->copy()->startOfDay();

        if (preg_match('/day after tomorrow|apres[- ]?demain|après[- ]?demain|بعد (?:بكرا|بكره|بكرة|غد|غدا)|ba3d (?:bokra|bukra|bokrah|bkra)/u', $t)) {
            return $day->addDays(2)->toDateString();
        }
        if (preg_match('/(?<![a-z])(?:tomorrow|tmrw|tomorow|demain|bokra|bukra|bokrah|bkra)(?![a-z])|بكرا|بكره|بكرة|غدا|غدًا|غداً|(?<!\p{Arabic})غد(?!\p{Arabic})/u', $t)) {
            return $day->addDay()->toDateString();
        }
        if (preg_match('/(?<![a-z])(?:today|tonight|lyom|l yom|el yom|elyom)(?![a-z])|aujourd hui|ce soir|اليوم|الليلة|هالليلة/u', $t)) {
            return $day->toDateString();
        }
        foreach (self::WEEKDAYS as $dow => $pattern) {
            if (preg_match($pattern, $t)) {
                while ($day->dayOfWeek !== $dow) {
                    $day->addDay();
                }

                return $day->toDateString();
            }
        }

        return null;
    }

    /** @return array{0: ?int, 1: int, 2: ?string} hour as written, minute, explicit am/pm */
    private static function time(string $t): array
    {
        $hour = null;
        $minute = 0;
        $meridiem = null;
        $suffix = fn (?string $s) => $s === null || $s === '' ? null : (str_starts_with($s, 'p') ? 'pm' : 'am');

        if (preg_match('/(\d{1,2})\s*[:h]\s*(\d{2})(?!\d)\s*(am|pm|a\.m\.?|p\.m\.?)?/u', $t, $x)) {
            [$hour, $minute, $meridiem] = [(int) $x[1], (int) $x[2], $suffix($x[3] ?? null)];
        } elseif (preg_match('/(\d{1,2})\s*(am|pm|a\.m\.?|p\.m\.?)(?![a-z])/u', $t, $x)) {
            [$hour, $meridiem] = [(int) $x[1], $suffix($x[2])];
        } elseif (preg_match('/(\d{1,2})\s*(?:h|heures?|hrs?)(?![a-z])/u', $t, $x)) {
            $hour = (int) $x[1];
        } elseif (preg_match('/(?<![a-z0-9])(?:at|sa3a|sa3et|3a|3al|3ala)\s*(\d{1,2})(?!\d)/u', $t, $x)) {
            $hour = (int) $x[1];
        } elseif (preg_match('/(?:@|à|الساعة|الساعه)\s*(\d{1,2})(?!\d)/u', $t, $x)) {
            $hour = (int) $x[1];
            if (preg_match('/ونص|ونصف|و نص/u', $t)) {
                $minute = 30;
            } elseif (preg_match('/وربع|و ربع/u', $t)) {
                $minute = 15;
            }
        } elseif (preg_match('/(?<![a-z])(?:noon|midi)(?![a-z])|الظهر|ظهرا|ظهراً/u', $t)) {
            return [12, 0, 'pm'];
        }

        if ($hour === null || $hour > 23 || $minute > 59) {
            return [null, 0, null];
        }

        return [$hour, $minute, $meridiem];
    }
}
