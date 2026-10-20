<?php

namespace App\Services;

use App\Models\Merchant;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\StaffMessenger;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\CurrentTenant;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reminds the shop owner (on his personal number, from the shop's own number) that the NeoTalab
 * subscription or trial is about to end. Sent once a day at 10:00 shop time, from REMIND_DAYS
 * (TRIAL_REMIND_DAYS for the short free trial) before the end date until the day after it.
 */
class BillingReminderService
{
    public const REMIND_DAYS = 3;

    public const TRIAL_REMIND_DAYS = 1;

    public const SEND_HOUR = 10;

    public function __construct(
        protected WhatsAppService $whatsapp,
        protected CurrentTenant $tenant,
        protected ShopTemplateService $templates,
        protected StaffMessenger $staff,
    ) {}

    public function sendDue(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        foreach (Merchant::query()->where('status', 'active')->get() as $merchant) {
            $to = ShopSettings::for($merchant)->reportWhatsapp();
            $ends = $this->endsAt($merchant);
            if ($to === '' || ! $ends || ! MerchantWhatsAppCredentials::resolve($merchant)) {
                continue;
            }

            $local = $now->copy()->setTimezone($merchant->timezone ?: config('app.timezone'));
            $daysLeft = (int) $local->copy()->startOfDay()->diffInDays($ends->copy()->setTimezone($local->timezone)->startOfDay(), false);
            $settings = $merchant->settings ?? [];

            $remindDays = ($settings['subscription_status'] ?? null) === 'trial' ? self::TRIAL_REMIND_DAYS : self::REMIND_DAYS;

            if ($local->hour !== self::SEND_HOUR || $daysLeft > $remindDays || $daysLeft < -1
                || ($settings['billing_reminder_last_sent'] ?? null) === $local->toDateString()) {
                continue;
            }

            // Plain text outside the 24-hour window is accepted by Meta and then dropped silently.
            $approved = $this->templates->isApproved($merchant, ShopTemplateService::BILLING_REMINDER);
            if (! $approved && ! $this->staff->numberInWindow($merchant, $to)) {
                Log::warning('Billing reminder not sent: its WhatsApp template is not approved yet and the report number has not messaged the shop number in the last 24 hours', [
                    'merchant_id' => $merchant->id, 'shop' => $merchant->name,
                ]);

                continue;
            }

            $params = [$merchant->name, $ends->copy()->setTimezone($local->timezone)->toDateString()];
            $message = $approved
                ? ShopTemplateService::message(ShopTemplateService::BILLING_REMINDER, $params)
                : ['type' => 'text', 'text' => __('whatsapp.billing.reminder', ['shop' => $params[0], 'date' => $params[1]], $merchant->locale ?? 'en')];

            $this->tenant->set($merchant);
            try {
                $this->whatsapp->sendToCustomer($merchant, $to, $message);
                $settings['billing_reminder_last_sent'] = $local->toDateString();
                $merchant->forceFill(['settings' => $settings])->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Billing reminder failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            } finally {
                $this->tenant->forget();
            }
        }

        return $sent;
    }

    protected function endsAt(Merchant $merchant): ?Carbon
    {
        $onTrial = (($merchant->settings ?? [])['subscription_status'] ?? null) === 'trial';

        return $onTrial ? $merchant->trial_ends_at : ($merchant->subscription_ends_at ?? $merchant->trial_ends_at);
    }
}
