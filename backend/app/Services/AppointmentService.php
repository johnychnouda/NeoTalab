<?php

namespace App\Services;

use App\Jobs\NotifyStaffOfAppointment;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ServiceProvider;
use App\Models\ShopHour;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bookable services (products with a duration). Free times come from the shop's opening hours,
 * how many customers it serves at once (appointment_capacity) and existing bookings. All times
 * are worked out in the shop's timezone; the database stores UTC.
 */
class AppointmentService
{
    /** Customers cannot book a start time closer than this. */
    private const MIN_NOTICE_MINS = 15;

    /**
     * @return Collection<int, Product>
     */
    public function services(): Collection
    {
        return Product::query()->whereNotNull('duration_mins')->where('is_active', true)->where('is_available', true)->orderBy('name')->get();
    }

    /**
     * Free start times ("HH:MM", shop time) for a service on a given local date.
     *
     * @return list<string>
     */
    public function availableSlots(Merchant $merchant, Product $service, Carbon $localDate, ?ServiceProvider $provider = null): array
    {
        $tz = $this->tz($merchant);
        $shop = ShopSettings::for($merchant);
        $day = $localDate->copy()->setTimezone($tz)->startOfDay();

        [$open, $close] = $this->hoursFor($day);
        if ($open === null) {
            return [];
        }

        $start = $day->copy()->setTimeFromTimeString($open);
        $end = $day->copy()->setTimeFromTimeString($close);
        if ($end->lte($start)) {
            $end->addDay(); // e.g. 18:00 – 02:00
        }

        $earliest = now()->setTimezone($tz)->addMinutes(self::MIN_NOTICE_MINS);
        $duration = (int) $service->duration_mins;
        $booked = Appointment::query()
            ->where('status', 'booked')
            ->where('starts_at', '<', $end->copy()->utc())
            ->where('ends_at', '>', $start->copy()->utc())
            ->get(['starts_at', 'ends_at', 'provider_id']);
        $providers = $this->providersFor($service);

        $slots = [];
        for ($t = $start->copy(); $t->copy()->addMinutes($duration)->lte($end); $t->addMinutes($shop->slotStepMins())) {
            if ($t->lt($earliest)) {
                continue;
            }
            $slotEnd = $t->copy()->addMinutes($duration);
            $overlapping = $booked->filter(fn ($a) => $a->starts_at->lt($slotEnd) && $a->ends_at->gt($t));
            $free = $providers->isEmpty()
                ? $overlapping->count() < $shop->appointmentCapacity()
                : $this->freeProviders($providers, $overlapping, $provider)->isNotEmpty();
            if ($free) {
                $slots[] = $t->format('H:i');
            }
        }

        return $slots;
    }

    /**
     * The people who do this service (stylists, barbers). Empty: the shop books by capacity only.
     *
     * @return Collection<int, ServiceProvider>
     */
    public function providersFor(Product $service): Collection
    {
        return ServiceProvider::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (ServiceProvider $p) => $p->offers($service))->values();
    }

    /**
     * Providers without a booking in that time (or only $wanted, if it is free). A booking made
     * before the shop listed its team still takes one person's time.
     *
     * @param  Collection<int, ServiceProvider>  $providers
     * @param  Collection<int, Appointment>  $overlapping
     * @return Collection<int, ServiceProvider>
     */
    private function freeProviders(Collection $providers, Collection $overlapping, ?ServiceProvider $wanted): Collection
    {
        $busy = $overlapping->pluck('provider_id')->filter()->all();
        $free = $providers->reject(fn (ServiceProvider $p) => in_array($p->id, $busy, true))->values();
        $unassigned = $overlapping->whereNull('provider_id')->count();
        if ($free->count() <= $unassigned) {
            return collect();
        }

        return $wanted ? $free->where('id', $wanted->id)->values() : $free;
    }

    /**
     * First day (from $from, up to 14 days ahead) with free times.
     *
     * @return array{0: Carbon, 1: list<string>}|null
     */
    public function nextAvailableDay(Merchant $merchant, Product $service, Carbon $from, ?ServiceProvider $provider = null): ?array
    {
        for ($i = 0; $i < 14; $i++) {
            $day = $from->copy()->addDays($i);
            $slots = $this->availableSlots($merchant, $service, $day, $provider);
            if ($slots !== []) {
                return [$day, $slots];
            }
        }

        return null;
    }

    /**
     * Book a slot if it is still free. Returns null when it was taken in the meantime.
     */
    public function book(Merchant $merchant, Customer $customer, Product $service, Carbon $localStart, ?ServiceProvider $provider = null): ?Appointment
    {
        $localStart = $localStart->copy()->setTimezone($this->tz($merchant));

        $appointment = DB::transaction(function () use ($merchant, $customer, $service, $localStart, $provider) {
            $sameDay = Appointment::query()->where('status', 'booked')->whereDate('starts_at', $localStart->copy()->utc()->toDateString())->lockForUpdate()->get();

            if (! in_array($localStart->format('H:i'), $this->availableSlots($merchant, $service, $localStart, $provider), true)) {
                return null;
            }

            $slotEnd = $localStart->copy()->addMinutes((int) $service->duration_mins);

            // One customer holds one time: with a team, a second person must not be booked for the same customer.
            if ($sameDay->contains(fn ($a) => $a->customer_id === $customer->id && $a->starts_at->lt($slotEnd) && $a->ends_at->gt($localStart))) {
                return null;
            }

            // With a team: the person asked for, else whoever is free with the fewest bookings that day.
            $providers = $this->providersFor($service);
            if ($providers->isNotEmpty()) {
                $overlapping = $sameDay->filter(fn ($a) => $a->starts_at->lt($slotEnd) && $a->ends_at->gt($localStart));
                $provider = $this->freeProviders($providers, $overlapping, $provider)
                    ->sortBy(fn (ServiceProvider $p) => $sameDay->where('provider_id', $p->id)->count())->first();
                if (! $provider) {
                    return null;
                }
            }

            return Appointment::create([
                'merchant_id' => $merchant->id,
                'customer_id' => $customer->id,
                'product_id' => $service->id,
                'provider_id' => $provider?->id,
                'service_name' => $service->name,
                'price' => $service->price,
                'starts_at' => $localStart->copy()->utc(),
                'ends_at' => $localStart->copy()->addMinutes((int) $service->duration_mins)->utc(),
                'status' => 'booked',
            ]);
        });

        if ($appointment) {
            NotifyStaffOfAppointment::dispatch($appointment->id)->afterCommit();

            $remind = ShopSettings::for($merchant)->reminderMins();
            $at = $appointment->starts_at->copy()->subMinutes($remind);
            if ($remind > 0 && $at->isFuture()) {
                SendAppointmentReminder::dispatch($appointment->id)->delay($at);
            }
        }

        return $appointment;
    }

    public function upcomingFor(Customer $customer): ?Appointment
    {
        return Appointment::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'booked')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    /** @return Collection<int, Appointment> */
    public function allUpcomingFor(Customer $customer): Collection
    {
        return Appointment::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'booked')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();
    }

    public function tz(Merchant $merchant): string
    {
        return $merchant->timezone ?: (string) config('app.timezone');
    }

    /**
     * @return array{0: ?string, 1: ?string} opening and closing time for that local day; [null, null] when closed
     */
    private function hoursFor(Carbon $day): array
    {
        $today = ShopHour::week()->get($day->dayOfWeek);
        if (! $today || $today->is_closed) {
            return [null, null];
        }

        return [substr($today->opens_at, 0, 5), substr($today->closes_at, 0, 5)];
    }
}
