<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform_name' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'grace_period_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'subscription_price' => ['sometimes', 'numeric', 'min:1'],
            'subscription_yearly_price' => ['sometimes', 'numeric', 'min:1'],
            'wa_phone_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wa_access_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'wa_verify_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'overdue_remind_days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'overdue_reminder_msg' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);

        $columns = [];
        $settings = [];

        $columnMap = [
            'platform_name' => 'platform_name',
            'currency' => 'currency',
            'timezone' => 'timezone',
            'trial_days' => 'trial_days',
            'grace_period_days' => 'grace_period_days',
            'subscription_price' => 'subscription_price',
            'subscription_yearly_price' => 'subscription_yearly_price',
        ];

        foreach ($columnMap as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        $settingsMap = [
            'wa_phone_id' => 'wa_phone_id',
            'wa_access_token' => 'wa_access_token',
            'wa_verify_token' => 'wa_verify_token',
            'overdue_remind_days' => 'overdue_remind_days',
            'overdue_reminder_msg' => 'overdue_reminder_msg',
        ];

        foreach ($settingsMap as $input => $jsonKey) {
            if (! array_key_exists($input, $data)) {
                continue;
            }

            $value = $data[$input];

            // Frontend sends a mask when the token is unchanged — never persist it.
            if ($input === 'wa_access_token' && in_array($value, ['••••••••', '********'], true)) {
                continue;
            }

            $settings[$jsonKey] = $value;
        }

        $payload = $columns;
        if ($settings !== []) {
            $payload['settings'] = $settings;
        }

        return $payload;
    }
}
