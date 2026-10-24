<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\ShopHour;
use App\Services\OrderTiming;
use Illuminate\Support\Carbon;

/**
 * Delivery or pickup times a customer can choose (florists, cakes, gifts): two-hour windows
 * inside the shop's opening hours, on the next DAYS_AHEAD open days, starting after the
 * preparation time. Times are the shop's local time.
 */
final class DeliverySchedule
{
    public const WINDOW_MINS = 120;

    public const DAYS_AHEAD = 7;

    /** @return list<Carbon> local dates (start of day) with at least one window left */
    public static function days(Merchant $merchant): array
    {
        $days = [];
        $today = self::now($merchant)->startOfDay();
        for ($i = 0; $i < 14 && count($days) < self::DAYS_AHEAD; $i++) {
            $day = $today->copy()->addDays($i);
            if (self::windows($merchant, $day) !== []) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** @return list<Carbon> local window starts on that day */
    public static function windows(Merchant $merchant, Carbon $day): array
    {
        $tz = $merchant->timezone ?: (string) config('app.timezone');
        $day = $day->copy()->setTimezone($tz)->startOfDay();
        $hours = ShopHour::week()->get($day->dayOfWeek);
        if (! $hours || $hours->is_closed) {
            return [];
        }

        $opens = $day->copy()->setTimeFromTimeString(substr($hours->opens_at, 0, 5));
        $closes = $day->copy()->setTimeFromTimeString(substr($hours->closes_at, 0, 5));
        if ($closes->lte($opens)) {
            $closes->addDay();
        }
        $earliest = self::now($merchant)->addMinutes(app(OrderTiming::class)->prepMins($merchant));

        $windows = [];
        for ($t = $opens->copy(); $t->copy()->addMinutes(self::WINDOW_MINS)->lte($closes); $t->addMinutes(self::WINDOW_MINS)) {
            if ($t->gte($earliest)) {
                $windows[] = $t->copy();
            }
        }

        return $windows;
    }

    /** The local start of a valid window from "YYYY-MM-DD HH:MM", or null. */
    public static function parse(Merchant $merchant, ?string $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            return null;
        }
        $tz = $merchant->timezone ?: (string) config('app.timezone');
        try {
            $at = Carbon::createFromFormat('Y-m-d H:i', $value, $tz);
        } catch (\Throwable) {
            return null;
        }
        foreach (self::windows($merchant, $at) as $window) {
            if ($window->equalTo($at)) {
                return $at;
            }
        }

        return null;
    }

    /** "Fri 9 Oct, 10:00–12:00" */
    public static function label(Merchant $merchant, Carbon $start, string $locale): string
    {
        $local = $start->copy()->setTimezone($merchant->timezone ?: (string) config('app.timezone'));

        return $local->copy()->locale($locale)->isoFormat('ddd D MMM').', '.$local->format('H:i').'–'.$local->copy()->addMinutes(self::WINDOW_MINS)->format('H:i');
    }

    private static function now(Merchant $merchant): Carbon
    {
        return now()->setTimezone($merchant->timezone ?: (string) config('app.timezone'));
    }
}
