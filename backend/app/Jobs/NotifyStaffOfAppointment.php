<?php

namespace App\Jobs;

use App\Jobs\Concerns\LoadsAppointment;
use App\Jobs\Concerns\SendsMerchantWhatsApp;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * New booking → every staff number, with Done / No-show buttons for after the visit.
 */
class NotifyStaffOfAppointment implements ShouldQueue
{
    use LoadsAppointment, Queueable, SendsMerchantWhatsApp;

    public int $tries = 1;

    public function __construct(public string $appointmentId) {}

    public function handle(StaffMessenger $messenger): void
    {
        [$appointment, $merchant] = $this->loadAppointment($this->appointmentId) ?? [null, null];
        if (! $appointment) {
            return;
        }

        foreach ($messenger->managers($merchant) as $member) {
            $locale = $messenger->localeOf($merchant, $member);
            $params = $this->appointmentParams($appointment, $merchant, $locale);
            $body = __('whatsapp.appointment.staff_new', $params, $locale);
            $buttons = [
                ["stf:appt_completed:{$appointment->id}", __('whatsapp.appointment.button_done', [], $locale)],
                ["stf:appt_no_show:{$appointment->id}", __('whatsapp.appointment.button_no_show', [], $locale)],
                ["stf:appt_change:{$appointment->id}", __('whatsapp.appointment.button_change', [], $locale)],
            ];
            $messenger->send($merchant, $member, $body, $buttons, ShopTemplateService::NEW_BOOKING, [
                $params['shop'], $params['service'], $params['date'], $params['time'], $params['price'], $params['phone'],
            ]);
        }
    }
}
