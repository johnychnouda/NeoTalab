<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Merchant;
use Illuminate\Validation\ValidationException;

class DriverService
{
    /**
     * @return list<Driver>
     */
    public function list(Merchant $merchant): array
    {
        return Driver::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(Merchant $merchant, array $data): Driver
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => ['Phone is required.']]);
        }

        if (Driver::query()->where('merchant_id', $merchant->id)->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => ['Driver with this phone already exists.']]);
        }

        return Driver::create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'phone' => $phone,
            'whatsapp_number' => $data['whatsappNumber'] ?? $data['whatsapp_number'] ?? $phone,
            'password' => $data['password'] ?? 'changeme123',
            'status' => 'off_duty',
            'availability' => 'available',
        ]);
    }

    public function setStatus(Merchant $merchant, string $driverId, string $status): Driver
    {
        $valid = ['on_duty', 'off_duty', 'on_break', 'paused'];
        if (! in_array($status, $valid, true)) {
            throw ValidationException::withMessages(['status' => ['Invalid status.']]);
        }

        $driver = Driver::query()->where('merchant_id', $merchant->id)->findOrFail($driverId);
        $driver->forceFill(['status' => $status])->save();

        return $driver;
    }

    public function deactivate(Merchant $merchant, string $driverId): void
    {
        $driver = Driver::query()->where('merchant_id', $merchant->id)->findOrFail($driverId);
        $driver->forceFill(['is_active' => false, 'status' => 'off_duty'])->save();
    }
}
