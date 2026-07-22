<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\ShopHour;
use App\Support\MerchantProfileSync;

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
            'wish_number' => $settings['wish_number'] ?? '',
            'wish_auto_confirm' => $settings['wish_auto_confirm'] ?? true,
            'wish_timeout_mins' => $settings['wish_timeout_mins'] ?? 10,
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
        if (array_key_exists('wishNumber', $payload) || array_key_exists('wish_number', $payload)) {
            $settings['wish_number'] = $payload['wishNumber'] ?? $payload['wish_number'];
        }
        if (array_key_exists('wishAutoConfirm', $payload) || array_key_exists('wish_auto_confirm', $payload)) {
            $settings['wish_auto_confirm'] = (bool) ($payload['wishAutoConfirm'] ?? $payload['wish_auto_confirm']);
        }
        if (array_key_exists('wishTimeoutMins', $payload) || array_key_exists('wish_timeout_mins', $payload)) {
            $settings['wish_timeout_mins'] = (int) ($payload['wishTimeoutMins'] ?? $payload['wish_timeout_mins']);
        }

        $merchant->settings = $settings;
        $merchant->save();

        return $merchant->fresh();
    }

    public function updateMode(Merchant $merchant, string $mode): Merchant
    {
        $valid = ['auto', 'busy', 'manual', 'closed'];
        if (! in_array($mode, $valid, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
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
                'opens_at' => $h['opensAt'] ?? $h['opens_at'] ?? '09:00',
                'closes_at' => $h['closesAt'] ?? $h['closes_at'] ?? '23:00',
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
        return DeliveryZone::create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'delivery_fee' => $data['deliveryFee'] ?? $data['delivery_fee'] ?? 0,
            'minimum_order' => $data['minimumOrder'] ?? $data['minimum_order'] ?? 0,
            'sort_order' => $data['sortOrder'] ?? $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);
    }

    public function deleteZone(Merchant $merchant, string $zoneId): void
    {
        $zone = DeliveryZone::query()->where('merchant_id', $merchant->id)->findOrFail($zoneId);
        $zone->forceFill(['is_active' => false])->save();
    }

    /**
     * @return list<ShopHour>
     */
    protected function defaultHours(Merchant $merchant): array
    {
        $rows = [];
        for ($d = 0; $d < 7; $d++) {
            $rows[] = new ShopHour([
                'merchant_id' => $merchant->id,
                'day_of_week' => $d,
                'opens_at' => '09:00',
                'closes_at' => '23:00',
                'is_closed' => false,
            ]);
        }

        return $rows;
    }
}
