<?php

namespace App\Jobs;

use App\Jobs\Concerns\LoadsAppointment;
use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\WhatsApp\StaffMessenger;
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

        $body = __('whatsapp.appointment.staff_cancelled', $this->appointmentParams($appointment, $merchant), $merchant->locale ?? 'en');

        foreach ($messenger->staff($merchant) as $member) {
            $messenger->send($merchant, $member, $body, []);
        }
    }
}
