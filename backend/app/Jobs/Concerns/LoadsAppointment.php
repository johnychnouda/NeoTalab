<?php

namespace App\Jobs\Concerns;

use App\Models\Appointment;
use App\Models\Merchant;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\Money;
use App\Support\OrderMessage;

trait LoadsAppointment
{
    /**
     * @return array{0: Appointment, 1: Merchant}|null
     */
    protected function loadAppointment(string $id): ?array
    {
        $appointment = Appointment::query()->withoutGlobalScopes()->with(['customer', 'provider' => fn ($q) => $q->withoutGlobalScopes()])->find($id);
        $merchant = $appointment ? Merchant::find($appointment->merchant_id) : null;

        if (! $appointment || ! $merchant || ! MerchantWhatsAppCredentials::resolve($merchant)) {
            return null;
        }

        return [$appointment, $merchant];
    }

    /**
     * @return array<string, string>
     */
    protected function appointmentParams(Appointment $appointment, Merchant $merchant, ?string $locale = null): array
    {
        $local = $appointment->starts_at->copy()->setTimezone($merchant->timezone ?: config('app.timezone'));

        return [
            'shop' => $merchant->name,
            'service' => $appointment->provider
                ? __('whatsapp.appointment.with', ['service' => $appointment->service_name, 'provider' => $appointment->provider->name], $locale ?? $merchant->locale ?? 'en')
                : $appointment->service_name,
            'date' => $local->locale($locale ?? $merchant->locale ?? 'en')->isoFormat('ddd D MMM'),
            'time' => $local->format('H:i'),
            'price' => Money::format((float) $appointment->price, $merchant->currency),
            'phone' => OrderMessage::phone($appointment->customer?->phone),
        ];
    }
}
