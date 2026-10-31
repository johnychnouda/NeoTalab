<?php

namespace App\Jobs;

use App\Jobs\Concerns\LoadsAppointment;
use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\WhatsApp\CustomerTemplates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reminds the customer of an upcoming booking (shop setting reminder_mins before it starts).
 */
class SendAppointmentReminder implements ShouldQueue
{
    use LoadsAppointment, Queueable, SendsMerchantWhatsApp;

    public int $tries = 2;

    public function __construct(public string $appointmentId) {}

    public function handle(): void
    {
        [$appointment, $merchant] = $this->loadAppointment($this->appointmentId) ?? [null, null];
        if (! $appointment || $appointment->status !== 'booked' || $appointment->reminded_at || $appointment->starts_at->isPast()) {
            return;
        }

        $appointment->forceFill(['reminded_at' => now()])->save();
        $locale = $appointment->customer?->locale ?? $merchant->locale ?? 'en';

        try {
            $params = $this->appointmentParams($appointment, $merchant, $locale);
            $this->sendCustomerAs(
                $merchant, (string) $appointment->customer?->phone,
                ['type' => 'text', 'text' => __('whatsapp.appointment.reminder', $params, $locale)],
                CustomerTemplates::BOOKING, [$params['service'], $merchant->name, $params['date'], $params['time']], $locale,
            );
        } catch (\Throwable $e) {
            Log::warning('Appointment reminder failed', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
        }
    }
}
