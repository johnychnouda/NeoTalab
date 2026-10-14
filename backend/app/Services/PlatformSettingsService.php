<?php

namespace App\Services;

use App\Models\PlatformSetting;

class PlatformSettingsService
{
    public function get(): PlatformSetting
    {
        return PlatformSetting::singleton();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): PlatformSetting
    {
        $setting = PlatformSetting::singleton();

        $columns = [];
        foreach ([
            'platform_name', 'currency', 'timezone', 'trial_days', 'grace_period_days',
            'subscription_price', 'subscription_yearly_price',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $columns[$key] = $data[$key];
            }
        }

        if ($columns !== []) {
            $setting->fill($columns);
        }

        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $current = $setting->settings ?? [];
            $merged = array_merge($current, $data['settings']);
            // A phone ID typed by hand replaces the QR-connected number: its own token no longer applies.
            if (array_key_exists('wa_phone_id', $data['settings']) && ($data['settings']['wa_phone_id'] ?? null) !== ($current['wa_phone_id'] ?? null)) {
                unset($merged['wa_phone_access_token'], $merged['wa_display_phone'], $merged['wa_waba_id'], $merged['wa_coexistence'], $merged['wa_registration_pin']);
            }
            $setting->settings = $merged;
        }

        $setting->save();

        return $setting->fresh();
    }
}
