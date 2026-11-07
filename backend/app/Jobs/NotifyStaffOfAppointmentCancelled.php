<?php

namespace App\Jobs;

use App\Jobs\Concerns\LoadsAppointment;
use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The customer cancelled a booking in the chat: tell the staff numbers, so nobody waits for them.
 * There is no template for this, so it reaches staff inside the 24-hour window only; the
 * dashboard's Appointments page shows the cancellation either way.
 */
class NotifyStaffOfAppointmentCancelled implements ShouldQueue
{
    use LoadsAppointment, Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $appointmentId) {}

    public function handle(StaffMessenger $messenger): void
    {
        [$appointment, $merchant] = $this->loadAppointment($this->appointmentId) ?? [null, null];
        if (! $appointment || $appointment->status !== 'cancelled') {
            return;
        }

        foreach ($messenger->managers($merchant) as $member) {
            $locale = $messenger->localeOf($merchant, $member);
            $params = $this->appointmentParams($appointment, $merchant, $locale);
            $body = __('whatsapp.appointment.staff_cancelled', $params, $locale);
            // A booking cancelled after its deposit was paid: the shop decides whether to send the money back.
            if ($appointment->deposit_paid_at) {
                $body .= "\n".__('whatsapp.appointment.deposit_note', ['amount' => Money::format((float) $appointment->deposit_amount, $merchant->currency)], $locale);
            }
            $messenger->send($merchant, $member, $body, [], ShopTemplateService::BOOKING_CANCELLED, [
                $merchant->name, (string) $params['service'], (string) $params['date'], (string) $params['time'], (string) $appointment->customer?->phone,
            ]);
        }
    }
}
