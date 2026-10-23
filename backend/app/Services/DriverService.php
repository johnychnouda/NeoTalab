<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\WhatsApp\StaffMessenger;
use App\Support\Countries;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverService
{
    /**
     * Each member carries `reachable`: false when WhatsApp can't deliver new orders to them yet
     * (outside the 24-hour window and no approved template), so the Team page can say so.
     *
     * @return list<Driver>
     */
    public function list(Merchant $merchant): array
    {
        $messenger = app(StaffMessenger::class);

        return Driver::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->each(fn (Driver $d) => $d->setAttribute('reachable', $messenger->reachable($merchant, $d)))
            ->all();
    }

    /**
     * Adds a member. Someone removed earlier comes back with the new name and role.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(Merchant $merchant, array $data): Driver
    {
        $phone = $this->number($merchant, $data['phone'] ?? '');
        $existing = $this->withNumber($merchant, $phone);

        if ($existing?->is_active) {
            throw ValidationException::withMessages(['phone' => [__('This number is already in your team.')]]);
        }
        if ($existing) {
            $existing->forceFill([
                'name' => $data['name'], 'role' => $data['role'] ?? 'driver', 'phone' => $phone, 'whatsapp_number' => $phone,
                'is_active' => true, 'status' => 'off_duty', 'availability' => 'available', 'cash_on_hand' => 0,
            ])->save();

            return $existing;
        }

        return Driver::create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'role' => $data['role'] ?? 'driver',
            'phone' => $phone,
            'whatsapp_number' => $phone,
            'password' => $data['password'] ?? 'changeme123',
            'status' => 'off_duty',
            'availability' => 'available',
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Merchant $merchant, string $driverId, array $data): Driver
    {
        $driver = Driver::query()->where('merchant_id', $merchant->id)->where('is_active', true)->findOrFail($driverId);
        $phone = $this->number($merchant, $data['phone'] ?? $driver->phone);
        $other = $this->withNumber($merchant, $phone);
        if ($other && $other->id !== $driver->id && $other->is_active) {
            throw ValidationException::withMessages(['phone' => [__('This number is already in your team.')]]);
        }

        $driver->forceFill([
            'name' => $data['name'] ?? $driver->name,
            'role' => $data['role'] ?? $driver->role,
            'phone' => $phone,
            'whatsapp_number' => $phone,
        ])->save();

        return $driver;
    }

    /** Team numbers are kept as +961…, the form incoming WhatsApp messages are matched against. */
    protected function number(Merchant $merchant, mixed $raw): string
    {
        $phone = Countries::toE164((string) $raw, ($merchant->settings ?? [])['country'] ?? 'LB');
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => [__('Enter a valid WhatsApp number, e.g. 70 123 456.')]]);
        }

        return $phone;
    }

    protected function withNumber(Merchant $merchant, string $phone): ?Driver
    {
        $digits = ltrim($phone, '+');

        return Driver::query()->where('merchant_id', $merchant->id)->get()
            ->first(fn (Driver $d) => in_array($digits, [
                preg_replace('/\D+/', '', (string) $d->phone),
                preg_replace('/\D+/', '', (string) $d->whatsapp_number),
            ], true));
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

    /**
     * Removing a driver never strands their delivery: one not collected yet goes to the next free
     * driver; one already on the road must be delivered first (the driver has the food and taps
     * Delivered).
     */
    public function deactivate(Merchant $merchant, string $driverId): void
    {
        $driver = Driver::query()->where('merchant_id', $merchant->id)->findOrFail($driverId);
        $order = $driver->active_order_id ? Order::query()->where('merchant_id', $merchant->id)->find($driver->active_order_id) : null;
        $open = $order && ! in_array($order->status, ['delivered', 'cancelled', 'rejected'], true);

        if ($open && $order->status === 'picked_up') {
            throw ValidationException::withMessages(['driver' => ["{$driver->name} is delivering order #{$order->number} right now. Remove them once it is delivered."]]);
        }

        DB::transaction(function () use ($driver, $order, $open) {
            $driver->forceFill(['is_active' => false, 'status' => 'off_duty', 'active_order_id' => null, 'availability' => 'available'])->save();
            if ($open && $order->driver_id === $driver->id) {
                $order->forceFill(['driver_id' => null, 'status' => $order->status === 'assigned' ? 'ready' : $order->status])->save();
            }
        });

        if ($open) {
            app(DriverDispatcher::class)->dispatchWaiting($merchant);
        }
    }
}
