<?php

namespace App\Services;

use App\Models\Merchant;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\CurrentTenant;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Stops shops whose free trial ended without a payment: the shop is suspended (its WhatsApp
 * number stops answering customers, so it costs NeoTalab nothing) and its subscription becomes
 * "pending". Marking it as paid in the owner portal turns it back on.
 */
class TrialExpiryService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
        protected CurrentTenant $tenant,
        protected ShopTemplateService $templates,
    ) {}

    public function expireDue(?Carbon $now = null): int
    {
        $now ??= now();
        $stopped = 0;

        $due = Merchant::query()
            ->where('status', 'active')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->get()
            ->filter(fn (Merchant $m) => (($m->settings ?? [])['subscription_status'] ?? null) === 'trial');

        foreach ($due as $merchant) {
            // From NeoTalab's number, in the owner's language; the shop's own number is the fallback until that template is approved.
            if (! app(BillingNoticeService::class)->paused($merchant, 'trial')) {
                $this->tellOwner($merchant);
            }

            $settings = $merchant->settings ?? [];
            $settings['subscription_status'] = 'pending';
            $merchant->forceFill(['status' => 'suspended', 'settings' => $settings])->save();

            activity('merchant')->performedOn($merchant)->log("{$merchant->name}: free trial ended — stopped until paid");
            $stopped++;
        }

        return $stopped;
    }

    /** One last message from the shop's own number to the owner's phone, before it goes quiet. */
    protected function tellOwner(Merchant $merchant): void
    {
        $to = ShopSettings::for($merchant)->reportWhatsapp();
        if ($to === '' || ! MerchantWhatsAppCredentials::resolve($merchant)) {
            return;
        }

        $date = $merchant->trial_ends_at->copy()->setTimezone($merchant->timezone ?: config('app.timezone'))->toDateString();
        $approved = $this->templates->resolve($merchant, ShopTemplateService::BILLING_REMINDER);
        $message = $approved
            ? ShopTemplateService::message($approved['name'], [$merchant->name, $date], [], $approved['language'])
            : ['type' => 'text', 'text' => __('whatsapp.billing.trial_ended', ['shop' => $merchant->name], $merchant->locale ?? 'en')];

        $this->tenant->set($merchant);
        try {
            $this->whatsapp->sendToCustomer($merchant, $to, $message);
        } catch (\Throwable $e) {
            Log::warning('Trial end message failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        } finally {
            $this->tenant->forget();
        }
    }
}
