<?php

namespace App\Services;

use App\Http\Resources\AdminMerchantResource;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ShopHour;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\BusinessKind;
use App\Support\Countries;
use App\Support\MapLocation;
use App\Support\MerchantProfileSync;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\ShopSettings;
use App\Support\ZoneArea;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MerchantSettingsService
{
    public function profile(Merchant $merchant): array
    {
        $settings = $merchant->settings ?? [];

        return [
            'shop_name' => $merchant->name,
            'shop_name_ar' => $merchant->name_ar,
            'shop_name_fr' => $merchant->name_fr,
            'slug' => $merchant->slug,
            'ops_whatsapp' => MerchantProfileSync::whatsapp($merchant),
            'mode' => $settings['mode'] ?? 'auto',
            'default_locale' => $merchant->locale ?? 'en',
            'currency' => $merchant->currency ?: 'USD',
            'whish_number' => ShopSettings::for($merchant)->whishNumber() ?? '',
            'whish_connected' => ShopSettings::for($merchant)->whishNumber() !== null,
            'wish_timeout_mins' => $settings['wish_timeout_mins'] ?? 15,
            'business_kind' => BusinessKind::of($merchant),
            'menu_profile' => BusinessKind::profile($merchant),
            'whatsapp_connected' => MerchantWhatsAppCredentials::resolve($merchant) !== null,
            'whatsapp_display' => $settings['bot']['display_phone_number'] ?? null,
            'subscription' => $this->subscription($merchant),
        ] + collect(ShopSettings::FIELDS)->keys()
            ->mapWithKeys(fn (string $key) => [$key => ShopSettings::for($merchant)->get($key)])
            ->all();
    }

    /**
     * What is done and what is left before the shop can take orders on WhatsApp.
     *
     * @return array<string, mixed>
     */
    public function setup(Merchant $merchant): array
    {
        $s = ShopSettings::for($merchant);
        $bookings = $s->businessMode() === 'appointments';
        $delivers = ! $bookings && $s->fulfillment() !== 'pickup';
        $team = Driver::query()->where('merchant_id', $merchant->id)->where('is_active', true)->get(['role']);
        $bot = ($merchant->settings ?? [])['bot'] ?? [];

        // The WhatsApp number is connected by NeoTalab and the type of business comes from the join
        // form, so neither is a step for the shop; the number shows as a status instead.
        $connected = MerchantWhatsAppCredentials::resolve($merchant) !== null;
        $steps = [
            ['key' => 'menu', 'done' => Product::query()->where('merchant_id', $merchant->id)->exists(), 'required' => true],
            ['key' => 'hours', 'done' => ShopHour::query()->where('merchant_id', $merchant->id)->exists(), 'required' => true],
        ];
        if ($delivers) {
            $steps[] = ['key' => 'zones', 'done' => DeliveryZone::query()->where('merchant_id', $merchant->id)->where('is_active', true)->exists(), 'required' => true];
        }
        if (! $bookings && $s->fulfillment() !== 'delivery') {
            $pin = app(TravelTime::class)->shopLocation($merchant) !== null;
            $steps[] = ['key' => 'pickup', 'done' => $pin || $s->pickupAddress() !== '', 'required' => true];
        }
        $steps[] = ['key' => 'team', 'done' => $team->isNotEmpty(), 'required' => false];
        $steps[] = ['key' => 'whish', 'done' => ShopSettings::for($merchant)->whishNumber() !== null, 'required' => false];
        $steps[] = ['key' => 'report', 'done' => $s->reportWhatsapp() !== '', 'required' => false];

        return [
            'steps' => $steps,
            'ready' => $connected && collect($steps)->where('required', true)->every(fn ($step) => $step['done']),
            'whatsapp_connected' => $connected,
            'whatsapp_number' => $bot['display_phone_number'] ?? null,
            'meta_payment_url' => $connected ? $this->metaPaymentUrl($merchant) : null,
            'drivers' => $team->where('role', 'driver')->count(),
            'staff' => $team->where('role', 'staff')->count(),
        ];
    }

    /**
     * Meta bills the shop's own WhatsApp account for its few paid messages: this opens that
     * account's payment methods (after the Meta login), on the shop's Meta business when known.
     */
    public function metaPaymentUrl(Merchant $merchant): ?string
    {
        $bot = ($merchant->settings ?? [])['bot'] ?? [];
        $wabaId = trim((string) ($bot['waba_id'] ?? ''));
        if ($wabaId === '') {
            return null;
        }

        $businessId = (string) ($bot['business_id'] ?? '');
        // Looked up once from Meta (at most hourly when it fails) and kept with the shop.
        if ($businessId === '' && Cache::add("meta-business-lookup:{$merchant->id}", true, 3600)
            && ($creds = MerchantWhatsAppCredentials::resolve($merchant))) {
            try {
                $businessId = (string) Http::withToken($creds['access_token'])->acceptJson()->timeout(5)
                    ->get(app(WhatsAppService::class)->graphUrl($wabaId), ['fields' => 'owner_business_info'])
                    ->json('owner_business_info.id');
            } catch (\Throwable) {
                $businessId = '';
            }
            if ($businessId !== '') {
                $settings = $merchant->settings;
                $settings['bot']['business_id'] = $businessId;
                $merchant->forceFill(['settings' => $settings])->saveQuietly();
            }
        }

        return 'https://business.facebook.com/latest/billing_hub/payment_methods'
            .($businessId !== '' ? '?business_id='.rawurlencode($businessId) : '');
    }

    /**
     * The shop's own view of its NeoTalab subscription: trial, paid or payment due, and when the
     * trial ends or the next payment is due.
     *
     * @return array{status: string, trial_ends_at: ?string, renews_at: ?string, billing_cycle: string, fee: float}
     */
    protected function subscription(Merchant $merchant): array
    {
        $m = (new AdminMerchantResource($merchant))->resolve();
        $yearly = $m['billing_cycle'] === 'yearly';
        $lastPaid = $m['last_payment_at'] ? Carbon::parse($m['last_payment_at']) : null;
        $renews = match (true) {
            $merchant->trial_ends_at && ! $lastPaid && ! $merchant->subscription_ends_at => $merchant->trial_ends_at,
            (bool) $merchant->subscription_ends_at => $merchant->subscription_ends_at,
            (bool) $lastPaid => $yearly ? $lastPaid->copy()->addYear() : $lastPaid->copy()->addMonth(),
            default => null,
        };

        return [
            'status' => (string) $m['subscription_status'],
            'trial_ends_at' => $merchant->trial_ends_at?->toIso8601String(),
            'renews_at' => $renews?->toIso8601String(),
            'billing_cycle' => $yearly ? 'yearly' : 'monthly',
            'fee' => $yearly ? $m['yearly_fee'] : $m['monthly_fee'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateProfile(Merchant $merchant, array $payload): Merchant
    {
        $settings = $merchant->settings ?? [];

        if (array_key_exists('shopName', $payload) || array_key_exists('shop_name', $payload)
            || array_key_exists('shopNameAr', $payload) || array_key_exists('shop_name_ar', $payload)) {
            MerchantProfileSync::applyShopName(
                $merchant,
                array_key_exists('shopName', $payload) || array_key_exists('shop_name', $payload)
                    ? ($payload['shopName'] ?? $payload['shop_name'])
                    : null,
                array_key_exists('shopNameAr', $payload) || array_key_exists('shop_name_ar', $payload)
                    ? ($payload['shopNameAr'] ?? $payload['shop_name_ar'])
                    : null,
            );
        }
        if (array_key_exists('defaultLocale', $payload) || array_key_exists('default_locale', $payload)) {
            MerchantProfileSync::applyLocale(
                $merchant,
                $payload['defaultLocale'] ?? $payload['default_locale'],
            );
        }
        if (array_key_exists('opsWhatsapp', $payload) || array_key_exists('ops_whatsapp', $payload)) {
            MerchantProfileSync::applyWhatsapp(
                $merchant,
                $settings,
                (string) ($payload['opsWhatsapp'] ?? $payload['ops_whatsapp'] ?? ''),
            );
        }
        if (array_key_exists('whish_number', $payload)) {
            // Digits only: the number customers send their Whish payment to. Empty turns Whish off for the shop.
            $typed = trim((string) $payload['whish_number']);
            $digits = preg_replace('/\D+/', '', $typed);
            // Spaces, dashes, brackets and a leading + are fine; letters are a mistake, not "no number".
            $badShape = $typed !== '' && preg_match('/[^\d\s+\-()]/', $typed) === 1;
            if ($badShape || ($digits !== '' && (strlen($digits) < 6 || strlen($digits) > 15))) {
                throw ValidationException::withMessages(['whish_number' => [__('Enter the shop\'s Whish number, digits only.')]]);
            }
            $settings['whish'] = array_merge($settings['whish'] ?? [], ['number' => $digits]);
        }
        if (array_key_exists('wishTimeoutMins', $payload) || array_key_exists('wish_timeout_mins', $payload)) {
            $minutes = $payload['wishTimeoutMins'] ?? $payload['wish_timeout_mins'];
            // The same 5 to 120 the settings screen allows.
            if (! is_numeric($minutes) || (int) $minutes < 5 || (int) $minutes > 120) {
                throw ValidationException::withMessages(['wish_timeout_mins' => [__('Choose between 5 and 120 minutes.')]]);
            }
            $settings['wish_timeout_mins'] = (int) $minutes;
        }

        // Changing the type of business resets its defaults (time unit, orders or bookings).
        if (isset($payload['business_kind']) && $payload['business_kind'] !== BusinessKind::of($merchant)
            && isset(BusinessKind::KINDS[$payload['business_kind']])) {
            $settings = BusinessKind::applyDefaults($settings, (string) $payload['business_kind']);
            unset($payload['timing_unit'], $payload['business_mode']);
        }

        if (filled($payload['report_whatsapp'] ?? null)) {
            $payload['report_whatsapp'] = Countries::toE164((string) $payload['report_whatsapp'], $settings['country'] ?? 'LB')
                ?? throw ValidationException::withMessages(['report_whatsapp' => [__('Enter a valid WhatsApp number, e.g. 70 123 456.')]]);
            // The shop's number can't send WhatsApp messages to itself, so the report would never arrive.
            $country = $settings['country'] ?? 'LB';
            $own = array_filter([
                Countries::toE164((string) ($settings['bot']['display_phone_number'] ?? ''), $country),
                Countries::toE164((string) MerchantProfileSync::whatsapp($merchant), $country),
            ]);
            if (in_array($payload['report_whatsapp'], $own, true)) {
                throw ValidationException::withMessages(['report_whatsapp' => [__('This is the shop\'s own WhatsApp number. Enter the personal number of the owner or manager.')]]);
            }
        }

        $ops = Validator::make(
            array_intersect_key($payload, ShopSettings::FIELDS),
            ShopSettings::rules(),
        )->validate();
        if (filled($ops['shop_location'] ?? null) && $ops['shop_location'] !== ($settings['shop_location'] ?? null)) {
            $ops['shop_location'] = MapLocation::resolve((string) $ops['shop_location'])
                ?? throw ValidationException::withMessages([
                    'shop_location' => [__('No location found in this link. In Google Maps, press and hold on your shop to drop a pin, then copy the numbers shown (e.g. 33.8938, 35.5018) and paste them here.')],
                ]);
        }
        foreach ($ops as $key => $value) {
            $settings[$key] = $value;
        }
        $merchant->settings = $settings;
        $merchant->save();

        return $merchant->fresh();
    }

    public function updateMode(Merchant $merchant, string $mode): Merchant
    {
        $valid = ['auto', 'busy', 'manual', 'closed'];
        if (! in_array($mode, $valid, true)) {
            throw ValidationException::withMessages([
                'mode' => ['Mode must be one of: '.implode(', ', $valid)],
            ]);
        }

        $settings = $merchant->settings ?? [];
        $settings['mode'] = $mode;
        $merchant->settings = $settings;
        $merchant->save();

        return $merchant->fresh();
    }

    /**
     * @return list<ShopHour>
     */
    public function hours(Merchant $merchant): array
    {
        $rows = ShopHour::query()->where('merchant_id', $merchant->id)->orderBy('day_of_week')->get();
        if ($rows->isEmpty()) {
            return $this->defaultHours($merchant);
        }

        return $rows->all();
    }

    /**
     * @param  list<array<string,mixed>>  $hours
     * @return list<ShopHour>
     */
    public function replaceHours(Merchant $merchant, array $hours): array
    {
        ShopHour::query()->where('merchant_id', $merchant->id)->delete();

        $created = [];
        foreach ($hours as $h) {
            $created[] = ShopHour::create([
                'merchant_id' => $merchant->id,
                'day_of_week' => (int) ($h['dayOfWeek'] ?? $h['day_of_week']),
                'opens_at' => $h['opensAt'] ?? $h['opens_at'] ?? ShopHour::DEFAULT_OPEN,
                'closes_at' => $h['closesAt'] ?? $h['closes_at'] ?? ShopHour::DEFAULT_CLOSE,
                'is_closed' => (bool) ($h['isClosed'] ?? $h['is_closed'] ?? false),
            ]);
        }

        return $created;
    }

    /**
     * @return list<DeliveryZone>
     */
    public function zones(Merchant $merchant): array
    {
        return DeliveryZone::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function createZone(Merchant $merchant, array $data): DeliveryZone
    {
        $location = $this->zonePin($data['location'] ?? null);

        return DeliveryZone::create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'location' => $location,
            'area' => ZoneArea::clean($data['area'] ?? null),
            'color' => $data['color'] ?? null,
            'delivery_fee' => $data['deliveryFee'] ?? $data['delivery_fee'] ?? 0,
            'minimum_order' => $data['minimumOrder'] ?? $data['minimum_order'] ?? 0,
            'delivery_mins' => $this->driveMins($merchant, $location, (int) ($data['deliveryMins'] ?? $data['delivery_mins'] ?? 15)),
            'sort_order' => $data['sortOrder'] ?? $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);
    }

    /**
     * Orders already placed keep the fee they were charged; only new orders use the change.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateZone(Merchant $merchant, string $zoneId, array $data): DeliveryZone
    {
        $zone = DeliveryZone::query()->where('merchant_id', $merchant->id)->where('is_active', true)->findOrFail($zoneId);
        $location = array_key_exists('location', $data) ? $this->zonePin($data['location']) : $zone->location;
        $zone->forceFill([
            'name' => $data['name'],
            'location' => $location,
            'area' => array_key_exists('area', $data) ? ZoneArea::clean($data['area']) : $zone->area,
            'color' => $data['color'] ?? $zone->color,
            'delivery_fee' => $data['deliveryFee'] ?? 0,
            'minimum_order' => $data['minimumOrder'] ?? 0,
            'delivery_mins' => $this->driveMins($merchant, $location, (int) ($data['deliveryMins'] ?? $zone->delivery_mins ?? 15)),
        ])->save();

        return $zone;
    }

    public function deleteZone(Merchant $merchant, string $zoneId): void
    {
        $zone = DeliveryZone::query()->where('merchant_id', $merchant->id)->findOrFail($zoneId);
        $zone->forceFill(['is_active' => false])->save();
    }

    /**
     * @return list<ShopHour>
     */
    /** A delivery area's Google Maps pin ("lat,lng"), or null when none was given. */
    protected function zonePin(mixed $input): ?string
    {
        if (! filled($input)) {
            return null;
        }

        return MapLocation::resolve((string) $input) ?? throw ValidationException::withMessages([
            'location' => [__('No location found in this link. In Google Maps, press and hold to drop a pin, then paste the link or the numbers (e.g. 33.8938, 35.5018).')],
        ]);
    }

    /** Google Maps driving time from the shop to this area, or the shop's own fallback minutes. */
    protected function driveMins(Merchant $merchant, ?string $location, int $fallback): int
    {
        if ($location && preg_match('/^(-?\d+\.\d+),(-?\d+\.\d+)$/', $location, $m)) {
            $google = app(TravelTime::class)->between($merchant, (float) $m[1], (float) $m[2]);
            if ($google) {
                return $google;
            }
        }

        return max(1, $fallback);
    }

    protected function defaultHours(Merchant $merchant): array
    {
        return ShopHour::defaults($merchant->id);
    }
}
