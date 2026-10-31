<?php

namespace App\Services;

use App\Models\Merchant;
use App\Services\WhatsApp\PlatformTemplateService;
use App\Services\WhatsApp\PlatformTemplateTexts;
use App\Support\MerchantProfileSync;
use Illuminate\Support\Carbon;

/**
 * Messages a shop owner about their NeoTalab subscription and connection, from NeoTalab's number.
 * Only through an approved template, in the owner's language when that one is approved: plain text
 * to a shop that never wrote to that number is dropped by WhatsApp.
 */
class BillingNoticeService
{
    public function __construct(protected PlatformTemplateService $templates) {}

    /** The payment is recorded: tell the owner the shop is paid until the given date. */
    public function paid(Merchant $merchant): bool
    {
        $until = $merchant->subscription_ends_at ? Carbon::parse($merchant->subscription_ends_at)->format('j F Y') : '—';

        return $this->send($merchant, PlatformTemplateService::PAID, [$merchant->name, $until]);
    }

    /**
     * The shop was stopped, so its customers can no longer order.
     *
     * @param  'trial'|'overdue'  $why
     */
    public function paused(Merchant $merchant, string $why): bool
    {
        return $this->send($merchant, PlatformTemplateService::SHOP_PAUSED, [
            $merchant->name,
            PlatformTemplateTexts::pausedReason($why, $this->language($merchant)),
        ]);
    }

    /**
     * The shop's WhatsApp connection stopped working, or will expire on $expires.
     */
    public function whatsappDown(Merchant $merchant, ?Carbon $expires = null): bool
    {
        $locale = $this->language($merchant);
        $reason = $expires
            ? PlatformTemplateTexts::downReason('expiring', $locale, $expires->copy()->locale($locale)->isoFormat('D MMMM'))
            : PlatformTemplateTexts::downReason('down', $locale);

        return $this->send($merchant, PlatformTemplateService::WHATSAPP_DOWN, [$merchant->name, $reason]);
    }

    /** @param  list<string>  $params */
    protected function send(Merchant $merchant, string $template, array $params): bool
    {
        $to = MerchantProfileSync::whatsapp($merchant);

        return $to ? $this->templates->sendEvent($to, $template, $merchant->locale, $params) : false;
    }

    protected function language(Merchant $merchant): string
    {
        return in_array($merchant->locale, PlatformTemplateService::LANGUAGES, true) ? $merchant->locale : 'en';
    }
}
