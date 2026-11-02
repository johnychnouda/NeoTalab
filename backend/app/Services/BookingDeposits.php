<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Merchant;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\CustomerTemplates;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\Money;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\Log;

/**
 * A booking that asks for a deposit: the time is held while the customer pays part of the price by Whish
 * transfer (the same way an order is paid), the shop checks its Whish account and confirms, and only then is
 * the booking on the calendar for good (ticket and reminder). No money is marked paid until the shop says so.
 *
 *   pending_deposit → (customer taps "I paid") → deposit_check → (shop: Received) → booked
 *                  ↘ (window ends) cancelled           ↘ (shop: Not received) cancelled
 */
class BookingDeposits
{
    public function __construct(
        protected AppointmentService $appointments,
        protected CustomerMessenger $customers,
        protected StaffMessenger $staff,
    ) {}

    /** What the customer reads under the booking: how to pay, and for how long the time is held. */
    public function instructions(Merchant $merchant, Appointment $appointment, string $locale): string
    {
        return __('whatsapp.appointment.deposit_instructions', $this->params($merchant, $appointment, $locale) + [
            'whish' => (string) ShopSettings::for($merchant)->whishNumber(),
            'minutes' => $this->appointments->depositWindow($merchant),
        ], $locale);
    }

    /** The customer tapped "I paid": the shop checks its Whish account next. */
    public function customerPaid(Merchant $merchant, Appointment $appointment): void
    {
        if (! $this->claim($appointment, 'pending_deposit', 'deposit_check')) {
            return; // already reported, confirmed, or released
        }
        $appointment->refresh()->load('customer');
        $locale = $this->customerLocale($merchant, $appointment);
        $p = $this->params($merchant, $appointment, $locale);

        // The customer: we are checking (a reply to their tap, so inside the 24 hours).
        $this->tell($merchant, $appointment, null, __('whatsapp.appointment.deposit_received', ['shop' => $merchant->name], $locale), []);

        // The team (or a lone owner): the amount, who from, and the note to look for.
        $mLocale = $merchant->locale ?? 'en';
        $m = $this->params($merchant, $appointment, $mLocale);
        $body = __('whatsapp.appointment.deposit_check', $m + ['phone' => OrderMessage::phone($appointment->customer?->phone)], $mLocale);
        $buttons = [
            ["stf:dp_ok:{$appointment->id}", __('whatsapp.staff.btn_whish_received', [], $mLocale)],
            ["stf:dp_no:{$appointment->id}", __('whatsapp.staff.btn_whish_missing', [], $mLocale)],
        ];
        foreach ($this->staff->managers($merchant) as $member) {
            $this->staff->send($merchant, $member, $body, $buttons, ShopTemplateService::DEPOSIT_CHECK, [
                $m['service'].' · '.$m['date'].' '.$m['time'], $merchant->name, $m['amount'], (string) $appointment->customer?->phone, $m['code'],
            ]);
        }
    }

    /** The shop found the money: the booking is on the calendar. False when it was already decided. */
    public function confirm(Merchant $merchant, Appointment $appointment): bool
    {
        $changed = Appointment::query()->whereKey($appointment->id)->whereIn('status', ['pending_deposit', 'deposit_check'])
            ->update(['status' => 'booked', 'deposit_paid_at' => now(), 'updated_at' => now()]);
        if ($changed !== 1) {
            return false;
        }
        $appointment->refresh()->load('customer');
        $this->appointments->confirmed($merchant, $appointment);

        $locale = $this->customerLocale($merchant, $appointment);
        $p = $this->params($merchant, $appointment, $locale);
        $this->tell($merchant, $appointment, CustomerTemplates::DEPOSIT_CONFIRMED,
            __('whatsapp.appointment.deposit_confirmed', $p, $locale), [$p['service'], $merchant->name, $p['date'], $p['time']]);

        return true;
    }

    /** The shop did not find the money: the booking is cancelled and the time is free. False when it was already decided. */
    public function refuse(Merchant $merchant, Appointment $appointment): bool
    {
        return $this->release($merchant, $appointment, ['deposit_check', 'pending_deposit'], 'shop', CustomerTemplates::DEPOSIT_NOT_FOUND, 'deposit_not_found');
    }

    /** The payment window ended without an "I paid": the time is released. */
    public function expire(Merchant $merchant, Appointment $appointment): void
    {
        $this->release($merchant, $appointment, ['pending_deposit'], 'system', CustomerTemplates::DEPOSIT_EXPIRED, 'deposit_expired');
    }

    /** @param  list<string>  $from */
    protected function release(Merchant $merchant, Appointment $appointment, array $from, string $by, string $event, string $key): bool
    {
        $changed = Appointment::query()->whereKey($appointment->id)->whereIn('status', $from)
            ->update(['status' => 'cancelled', 'cancelled_by' => $by, 'updated_at' => now()]);
        if ($changed !== 1) {
            return false;
        }
        $appointment->refresh()->load('customer');
        $locale = $this->customerLocale($merchant, $appointment);
        $p = $this->params($merchant, $appointment, $locale);
        $this->tell($merchant, $appointment, $event, __("whatsapp.appointment.{$key}", $p, $locale), [$p['service'], $merchant->name, $p['date'], $p['time']]);

        return true;
    }

    /**
     * The deposit's figures in one language.
     *
     * @return array<string, string>
     */
    public function params(Merchant $merchant, Appointment $appointment, string $locale): array
    {
        $local = $appointment->starts_at->copy()->setTimezone($this->appointments->tz($merchant));
        $deposit = (float) $appointment->deposit_amount;

        return [
            'shop' => $merchant->name,
            'service' => (string) $appointment->service_name,
            'date' => $local->copy()->locale($locale)->isoFormat('ddd D MMM'),
            'time' => $local->format('H:i'),
            'amount' => Money::format($deposit, $merchant->currency),
            'rest' => Money::format(max(0, (float) $appointment->price - $deposit), $merchant->currency),
            'code' => (string) $appointment->deposit_code,
        ];
    }

    protected function customerLocale(Merchant $merchant, Appointment $appointment): string
    {
        return $appointment->customer?->locale ?? $merchant->locale ?? 'en';
    }

    protected function claim(Appointment $appointment, string $from, string $to): bool
    {
        return Appointment::query()->whereKey($appointment->id)->where('status', $from)->update(['status' => $to, 'updated_at' => now()]) === 1;
    }

    /**
     * The customer is told: the normal message inside WhatsApp's 24 hours, the approved template after.
     *
     * @param  list<string>  $templateParams
     */
    protected function tell(Merchant $merchant, Appointment $appointment, ?string $event, string $text, array $templateParams): void
    {
        try {
            $this->customers->send($merchant, (string) $appointment->customer?->phone, ['type' => 'text', 'text' => $text], $event, $templateParams, $this->customerLocale($merchant, $appointment));
        } catch (\Throwable $e) {
            Log::warning('Deposit message not sent', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
        }
    }
}
