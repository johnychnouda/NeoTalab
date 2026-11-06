<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\ShopHour;
use App\Services\OrderTiming;
use Illuminate\Support\Carbon;

/**
 * Delivery or pickup times a customer can choose instead of the shop's own time: windows inside the
 * shop's opening hours (one hour for shops that count in minutes, two for shops that count in hours),
 * or whole days for shops that count in days, on the next DAYS_AHEAD open days, never earlier than
 * the shop's own preparation time allows. Times are the shop's local time.
 */
final class DeliverySchedule
{
    public const DAYS_AHEAD = 7;

    /** Length of one choosable slot, in minutes: 60 (minutes shops), 120 (hours shops), a whole day (days shops). */
    public static function windowMins(Merchant $merchant): int
    {
        return match (ShopSettings::for($merchant)->timingUnit()) {
            'hours' => 120,
            'days' => 24 * 60,
            default => 60,
        };
    }

    /** Shops that count in days offer a day, not a time. */
    public static function byDay(Merchant $merchant): bool
    {
        return ShopSettings::for($merchant)->timingUnit() === 'days';
    }

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
        if (self::byDay($merchant)) {
            // One slot a day, from opening time; never before the shop's own days allow.
            return $day->gte(app(OrderTiming::class)->earliestDay($merchant)) ? [$opens] : [];
        }

        $earliest = self::now($merchant)->addMinutes(app(OrderTiming::class)->prepMins($merchant));
        $len = self::windowMins($merchant);

        $windows = [];
        for ($t = $opens->copy(); $t->copy()->addMinutes($len)->lte($closes); $t->addMinutes($len)) {
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

    /** "Fri 9 Oct, 10:00–12:00", or just "Fri 9 Oct" for a shop that counts in days. */
    public static function label(Merchant $merchant, Carbon $start, string $locale): string
    {
        $local = $start->copy()->setTimezone($merchant->timezone ?: (string) config('app.timezone'));
        $day = $local->copy()->locale($locale)->isoFormat('ddd D MMM');

        return self::byDay($merchant) ? $day : $day.', '.$local->format('H:i').'–'.$local->copy()->addMinutes(self::windowMins($merchant))->format('H:i');
    }

    private static function now(Merchant $merchant): Carbon
    {
        return now()->setTimezone($merchant->timezone ?: (string) config('app.timezone'));
    }
}
