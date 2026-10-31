<?php

namespace App\Services;

use App\Models\Merchant;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Once a day, asks Meta whether each running shop's own WhatsApp still works. A shop whose connection
 * broke (or whose token expires within a week) stops answering customers without any sign, so the
 * owner of the shop is told on WhatsApp, in their language, to reconnect, and the NeoTalab owner gets
 * an Inbox alert. The shop is told again every few days until it is fixed.
 */
class ShopWhatsAppWatch
{
    public const REPEAT_DAYS = 3;

    public const EXPIRY_DAYS = 7;

    public function __construct(
        protected MerchantBotService $bots,
        protected BillingNoticeService $notices,
        protected SystemAlertService $alerts,
    ) {}

    /** @return int how many shop owners were told */
    public function run(?Carbon $now = null): int
    {
        $now ??= now();
        $told = 0;

        foreach (Merchant::query()->where('status', 'active')->get() as $merchant) {
            if (! in_array(($merchant->settings ?? [])['subscription_status'] ?? null, ['trial', 'paid'], true) || ! MerchantWhatsAppCredentials::resolve($merchant)) {
                continue;
            }

            try {
                $told += $this->check($merchant, $now) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('WhatsApp check failed for a shop', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
            }
        }

        return $told;
    }

    protected function check(Merchant $merchant, Carbon $now): bool
    {
        $result = $this->bots->testBot($merchant);
        $merchant->refresh();
        $settings = $merchant->settings ?? [];
        $expires = ($settings['bot']['token_expires'] ?? null) ? Carbon::parse($settings['bot']['token_expires']) : null;

        $expiring = $expires && $expires->isBefore($now->copy()->addDays(self::EXPIRY_DAYS));
        if ($result['success'] && ! $expiring) {
            if (isset($settings['wa_down_notified_at'])) {
                unset($settings['wa_down_notified_at']);
                $merchant->forceFill(['settings' => $settings])->save();
            }

            return false;
        }

        $this->alerts->record('warning', $merchant->name.': its WhatsApp connection '.($result['success']
            ? 'expires on '.$expires->toDateString().'. The owner was asked to reconnect.'
            : 'is not working ('.($result['error'] ?? 'unknown error').'). The owner was asked to reconnect.'), ['merchant_id' => $merchant->id]);

        $last = isset($settings['wa_down_notified_at']) ? Carbon::parse($settings['wa_down_notified_at']) : null;
        if ($last && $last->isAfter($now->copy()->subDays(self::REPEAT_DAYS))) {
            return false;
        }

        if (! $this->notices->whatsappDown($merchant, $result['success'] ? $expires : null)) {
            return false;
        }

        $settings['wa_down_notified_at'] = $now->toIso8601String();
        $merchant->forceFill(['settings' => $settings])->save();

        return true;
    }
}
