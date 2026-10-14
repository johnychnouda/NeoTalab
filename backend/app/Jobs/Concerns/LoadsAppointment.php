<?php

namespace App\Jobs\Concerns;

use App\Models\Appointment;
use App\Models\Merchant;
use App\Support\MerchantWhatsAppCredentials;

trait LoadsAppointment
{
    /**
     * @return array{0: Appointment, 1: Merchant}|null
     */
    protected function loadAppointment(string $id): ?array
    {
        $appointment = Appointment::query()->withoutGlobalScopes()->with('customer')->find($id);
        $merchant = $appointment ? Merchant::find($appointment->merchant_id) : null;

        if (! $appointment || ! $merchant || ! MerchantWhatsAppCredentials::resolve($merchant)) {
            return null;
        }

        return [$appointment, $merchant];
    }

    /**
     * @return array<string, string>
     */
    protected function appointmentParams(Appointment $appointment, Merchant $merchant): array
    {
        $local = $appointment->starts_at->copy()->setTimezone($merchant->timezone ?: config('app.timezone'));

        return [
            'shop' => $merchant->name,
            'service' => $appointment->service_name,
            'date' => $local->format('D j M'),
            'time' => $local->format('H:i'),
            'price' => number_format((float) $appointment->price, 2).' '.$merchant->currency,
            'phone' => (string) $appointment->customer?->phone,
        ];
    }
}
