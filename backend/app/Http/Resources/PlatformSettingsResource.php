<?php

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlatformSetting */
class PlatformSettingsResource extends JsonResource
{
    public function __construct($resource, protected ?string $passwordChangedAt = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $extra = $this->settings ?? [];

        return [
            'platformName' => $this->platform_name,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'subscriptionPrice' => (float) $this->subscription_price,
            'subscriptionYearlyPrice' => (float) $this->subscription_yearly_price,
            'trialDays' => $this->trial_days,
            'gracePeriodDays' => $this->grace_period_days,
            'waPhoneId' => $extra['wa_phone_id'] ?? '',
            'waToken' => ! empty($extra['wa_access_token']) ? '••••••••' : '',
            'waTokenConfigured' => ! empty($extra['wa_access_token']),
            'waVerifyToken' => $extra['wa_verify_token'] ?? '',
            'overdueDays' => $extra['overdue_remind_days'] ?? 3,
            'overdueReminderMsg' => $extra['overdue_reminder_msg'] ?? null,
            'passwordChangedAt' => $this->passwordChangedAt,
        ];
    }
}
