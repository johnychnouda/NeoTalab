<?php

namespace App\Support;

use App\Models\Merchant;

/**
 * Typed access to the operational settings a merchant sets in the dashboard
 * (stored in merchants.settings), with defaults.
 */
final class ShopSettings
{
    /** @var array<string, array{0: mixed, 1: string}> key => [default, type] */
    public const FIELDS = [
        'prep_time_mins' => [30, 'int'],      // ETA quoted when the bot confirms an order
        'late_alert_mins' => [15, 'int'],     // extra minutes after the ETA before the "sorry for the delay" message; 0 = off
        'lbp_rate' => [null, 'float'],        // LBP per 1 unit of the shop currency; null = LBP not accepted
        'report_whatsapp' => ['', 'string'],  // owner's personal number for the nightly report; '' = off
        'report_hour' => [23, 'int'],         // local hour (0-23) the report is sent
        'bot_pause_mins' => [60, 'int'],      // bot stays quiet in a chat this long after the merchant types in it
        'rating_delay_mins' => [30, 'int'],   // wait after delivery before asking for a rating; 0 = off
        'fulfillment' => ['delivery', 'string'], // delivery | pickup | both
        'pickup_address' => ['', 'string'],   // where pickup customers collect their order
        'business_mode' => ['orders', 'string'], // orders | appointments | both
        'appointment_capacity' => [1, 'int'], // customers served at the same time (chairs, rooms, staff)
        'slot_step_mins' => [30, 'int'],      // bookable start times every N minutes
        'reminder_mins' => [120, 'int'],      // appointment reminder this long before; 0 = off
        'timing_unit' => ['minutes', 'string'], // minutes (food) | hours | days (retail, made to order)
        'prep_hours' => [2, 'int'],           // hours shops: usual time
        'delivery_days_min' => [1, 'int'],    // days shops: "1–2 working days"
        'delivery_days_max' => [2, 'int'],
        'shop_location' => ['', 'string'],    // "lat,lng" or a Google Maps link, for live driving times
        'business_kind' => ['', 'string'],    // see BusinessKind::KINDS; '' = from merchants.business_type
    ];

    public function __construct(private readonly array $settings) {}

    public static function for(Merchant $merchant): self
    {
        return new self($merchant->settings ?? []);
    }

    public function get(string $key): mixed
    {
        [$default] = self::FIELDS[$key];
        $value = $this->settings[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function prepTimeMins(): int
    {
        return max(1, (int) $this->get('prep_time_mins'));
    }

    /** How the shop counts time: 'minutes' (food), 'hours', or 'days' (retail, made to order). */
    public function timingUnit(): string
    {
        $unit = (string) $this->get('timing_unit');

        return in_array($unit, ['minutes', 'hours', 'days'], true) ? $unit : 'minutes';
    }

    public function prepHours(): int
    {
        return max(1, (int) $this->get('prep_hours'));
    }

    /** @return array{0: int, 1: int} working days, e.g. [2, 3] */
    public function deliveryDays(): array
    {
        $min = max(0, (int) $this->get('delivery_days_min'));

        return [$min, max($min, (int) $this->get('delivery_days_max'))];
    }

    public function lateAlertMins(): int
    {
        return max(0, (int) $this->get('late_alert_mins'));
    }

    public function lbpRate(): ?float
    {
        $rate = $this->get('lbp_rate');

        return $rate !== null && (float) $rate > 0 ? (float) $rate : null;
    }

    public function reportWhatsapp(): string
    {
        return trim((string) $this->get('report_whatsapp'));
    }

    public function reportHour(): int
    {
        return min(23, max(0, (int) $this->get('report_hour')));
    }

    public function botPauseMins(): int
    {
        return max(1, (int) $this->get('bot_pause_mins'));
    }

    public function fulfillment(): string
    {
        $value = (string) $this->get('fulfillment');

        return in_array($value, ['delivery', 'pickup', 'both'], true) ? $value : 'delivery';
    }

    public function pickupAddress(): string
    {
        return trim((string) $this->get('pickup_address'));
    }

    public function businessMode(): string
    {
        $mode = (string) $this->get('business_mode');

        return in_array($mode, ['orders', 'appointments', 'both'], true) ? $mode : 'orders';
    }

    public function appointmentCapacity(): int
    {
        return max(1, (int) $this->get('appointment_capacity'));
    }

    public function slotStepMins(): int
    {
        return in_array((int) $this->get('slot_step_mins'), [10, 15, 20, 30, 45, 60], true) ? (int) $this->get('slot_step_mins') : 30;
    }

    public function reminderMins(): int
    {
        return max(0, (int) $this->get('reminder_mins'));
    }

    public function ratingDelayMins(): int
    {
        return max(0, (int) $this->get('rating_delay_mins'));
    }

    /** Rating question in the delivered message; rating_delay_mins = 0 switches it off. */
    public function asksForRating(): bool
    {
        return $this->ratingDelayMins() > 0;
    }

    /**
     * Shop-currency amount in LBP, rounded up to the nearest 1,000 (the smallest note in use).
     */
    public function toLbp(float $amount): ?int
    {
        $rate = $this->lbpRate();

        return $rate ? (int) (ceil($amount * $rate / 1000) * 1000) : null;
    }

    /**
     * Validation rules for the dashboard fields.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'prep_time_mins' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'late_alert_mins' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'lbp_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000000'],
            'report_whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'report_hour' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'bot_pause_mins' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'rating_delay_mins' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'fulfillment' => ['sometimes', 'string', 'in:delivery,pickup,both'],
            'pickup_address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'business_mode' => ['sometimes', 'string', 'in:orders,appointments,both'],
            'appointment_capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'slot_step_mins' => ['sometimes', 'integer', 'in:10,15,20,30,45,60'],
            'reminder_mins' => ['sometimes', 'integer', 'min:0', 'max:2880'],
            'timing_unit' => ['sometimes', 'string', 'in:minutes,hours,days'],
            'prep_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'delivery_days_min' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'delivery_days_max' => ['sometimes', 'integer', 'min:0', 'max:60', 'gte:delivery_days_min'],
            'shop_location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'business_kind' => ['sometimes', 'string', 'in:'.implode(',', array_keys(BusinessKind::KINDS))],
        ];
    }
}
