<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\ShopHour;
use App\Support\DeliverySchedule;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;

/**
 * What the customer is promised, for every kind of shop:
 * - minutes / hours (food, services): an arrival or pickup time = preparation + driving time;
 * - days (retail, made to order): a delivery date, counted in the shop's working days.
 */
class OrderTiming
{
    /** Food shops: the customer is told again only when the time moves by this much or more. */
    public const SIGNIFICANT_MINS = 10;

    /** Maximum automatic "taking longer" updates per order before staff are asked to step in. */
    public const MAX_UPDATES = 3;

    public function __construct(protected TravelTime $travel) {}

    public function inDays(Merchant $merchant): bool
    {
        return ShopSettings::for($merchant)->timingUnit() === 'days';
    }

    /** Preparation time in minutes (minutes and hours shops). */
    public function prepMins(Merchant $merchant): int
    {
        $s = ShopSettings::for($merchant);

        return $s->timingUnit() === 'hours' ? $s->prepHours() * 60 : $s->prepTimeMins();
    }

    /** Short label for the shop's Accept button: "30 min", "2 h", "2–3 days". */
    public function label(Merchant $merchant, string $locale): string
    {
        $s = ShopSettings::for($merchant);

        return match ($s->timingUnit()) {
            'hours' => __('whatsapp.timing.hours_short', ['n' => $s->prepHours()], $locale),
            'days' => __('whatsapp.timing.days_short', ['range' => $this->dayRange($merchant)], $locale),
            default => __('whatsapp.timing.mins_short', ['n' => $s->prepTimeMins()], $locale),
        };
    }

    /**
     * When the customer's latest promise runs out: a delivery still at the shop is judged by when it should leave
     * (what the customer was told), anything else by its promised time.
     */
    public function dueAt(Order $order): Carbon
    {
        return $order->fulfillment === 'delivery' && $order->status !== 'picked_up'
            ? $order->promised_at->copy()->subMinutes((int) $order->travel_mins)
            : $order->promised_at;
    }

    /** The promise made when the shop accepts. */
    public function promise(Merchant $merchant, Order $order): Carbon
    {
        // A time the customer chose: the end of that window.
        if ($order->deliver_at) {
            // A day chosen by a shop that counts in days: the end of that day; otherwise the end of the chosen window.
            return DeliverySchedule::byDay($merchant)
                ? $order->deliver_at->copy()->setTimezone($this->tz($merchant))->endOfDay()->utc()
                : $order->deliver_at->copy()->addMinutes(DeliverySchedule::windowMins($merchant));
        }

        if ($this->inDays($merchant)) {
            // End of the promised day in the shop's timezone, stored in UTC like every timestamp.
            return $this->addWorkingDays($merchant, $this->local($merchant), ShopSettings::for($merchant)->deliveryDays()[1])->endOfDay()->utc();
        }

        $mins = $this->prepMins($merchant) + ($order->fulfillment === 'delivery' ? $this->travel->minutes($merchant, $order) : 0);

        return now()->addMinutes($mins);
    }

    /** "arriving around 8:40 PM" / "ready for pickup around 8:25 PM" / "delivered by Thursday 9 October". */
    public function phrase(Merchant $merchant, Order $order, string $locale): string
    {
        $at = $order->promised_at ?? $this->promise($merchant, $order);

        // The window the customer chose, until a late update gives a new time.
        if ($order->deliver_at && $at->lte($order->deliver_at->copy()->addMinutes(DeliverySchedule::windowMins($merchant)))) {
            return __($order->fulfillment === 'pickup' ? 'whatsapp.timing.ready_scheduled' : 'whatsapp.timing.scheduled', [
                'when' => DeliverySchedule::label($merchant, $order->deliver_at, $locale),
            ], $locale);
        }

        if ($this->inDays($merchant)) {
            return __($order->fulfillment === 'pickup' ? 'whatsapp.timing.ready_by_date' : 'whatsapp.timing.by_date', [
                'date' => $this->date($merchant, $at, $locale),
            ], $locale);
        }

        // Delivery: first when the preparation is done (it leaves then); the arrival time comes when it is on its way.
        if ($order->fulfillment === 'delivery' && $order->status !== 'picked_up') {
            return __('whatsapp.timing.preparing_ready_around', [
                'time' => $this->time($merchant, $this->dueAt($order), $locale),
            ], $locale);
        }

        // On its way: "arriving in about 15 min (around 10:45 AM)"; past the time, only the clock time.
        $mins = (int) ceil(now()->diffInSeconds($at, false) / 60);
        if ($order->fulfillment === 'delivery' && $mins >= 1) {
            return __('whatsapp.timing.arriving_in', ['duration' => $this->duration($mins, $locale), 'time' => $this->time($merchant, $at, $locale)], $locale);
        }

        return __($order->fulfillment === 'pickup' ? 'whatsapp.timing.ready_around' : 'whatsapp.timing.arriving_around', [
            'time' => $this->time($merchant, $at, $locale),
        ], $locale);
    }

    /** The estimate shown on the receipt, before the shop accepts: the preparation only, a delivery's arrival time comes when it leaves. */
    public function estimate(Merchant $merchant, string $fulfillment, string $locale): string
    {
        if ($this->inDays($merchant)) {
            return __($fulfillment === 'pickup' ? 'whatsapp.timing.estimate_days_pickup' : 'whatsapp.timing.estimate_days', ['range' => $this->dayRange($merchant)], $locale);
        }

        return __($fulfillment === 'pickup' ? 'whatsapp.timing.estimate_ready' : 'whatsapp.timing.estimate_delivery', [
            'duration' => $this->duration($this->prepMins($merchant), $locale),
        ], $locale);
    }

    /** New arrival time once the order leaves the shop: now + Google's driving time with live traffic (the time saved at accept if Google can't answer). */
    public function arrivalFromNow(Merchant $merchant, Order $order): Carbon
    {
        return now()->addMinutes($this->travel->live($merchant, $order) ?? $this->travel->minutes($merchant, $order));
    }

    public function nextWorkingDayEnd(Merchant $merchant, Carbon $from): Carbon
    {
        return $this->addWorkingDays($merchant, $from->copy()->setTimezone($this->tz($merchant)), 1)->endOfDay()->utc();
    }

    public function time(Merchant $merchant, Carbon $at, string $locale): string
    {
        return $at->copy()->setTimezone($this->tz($merchant))->locale($locale)->isoFormat($locale === 'en' ? 'h:mm A' : 'HH:mm');
    }

    public function date(Merchant $merchant, Carbon $at, string $locale): string
    {
        return $at->copy()->setTimezone($this->tz($merchant))->locale($locale)->isoFormat('dddd D MMMM');
    }

    protected function duration(int $mins, string $locale): string
    {
        return $mins >= 90
            ? __('whatsapp.timing.about_hours', ['n' => round($mins / 60, 1)], $locale)
            : __('whatsapp.timing.about_mins', ['n' => $mins], $locale);
    }

    protected function dayRange(Merchant $merchant): string
    {
        [$min, $max] = ShopSettings::for($merchant)->deliveryDays();

        return $min === $max ? (string) $max : "{$min}–{$max}";
    }

    /** The first day a customer can choose in a shop that counts in days: today plus its minimum days, skipping closed days. */
    public function earliestDay(Merchant $merchant): Carbon
    {
        return $this->addWorkingDays($merchant, $this->local($merchant), ShopSettings::for($merchant)->deliveryDays()[0])->startOfDay();
    }

    /** Adds working days, skipping days the shop is closed (Sundays if no hours are set). */
    protected function addWorkingDays(Merchant $merchant, Carbon $from, int $days): Carbon
    {
        $closed = ShopHour::query()->where('is_closed', true)->pluck('day_of_week')->all();
        if ($closed === [] && ! ShopHour::query()->exists()) {
            $closed = [Carbon::SUNDAY];
        }

        $date = $from->copy();
        $added = 0;
        while ($added < $days) {
            $date->addDay();
            if (! in_array($date->dayOfWeek, $closed, true)) {
                $added++;
            }
        }

        return $date;
    }

    protected function local(Merchant $merchant): Carbon
    {
        return now()->setTimezone($this->tz($merchant));
    }

    protected function tz(Merchant $merchant): string
    {
        return $merchant->timezone ?: (string) config('app.timezone');
    }
}
