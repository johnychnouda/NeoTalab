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
            $setting->settings = array_merge($setting->settings ?? [], $data['settings']);
        }

        $setting->save();

        return $setting->fresh();
    }
}
