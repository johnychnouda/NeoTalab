<?php

namespace App\Services;

use App\Models\Merchant;
use App\Services\WhatsApp\PlatformTemplateService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\MerchantProfileSync;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Messages a shop owner about their NeoTalab subscription, from NeoTalab's number. Only through an
 * approved template: plain text to a shop that never wrote to that number is dropped by WhatsApp.
 */
class BillingNoticeService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
        protected PlatformTemplateService $templates,
    ) {}

    /** The payment is recorded: tell the owner the shop is paid until the given date. */
    public function paid(Merchant $merchant): bool
    {
        $to = MerchantProfileSync::whatsapp($merchant);
        if (! $to || ! $this->templates->approved(PlatformTemplateService::PAID)) {
            return false;
        }

        $until = $merchant->subscription_ends_at ? Carbon::parse($merchant->subscription_ends_at)->format('j F Y') : '—';

        try {
            $this->whatsapp->sendPlatform($to, [
                'type' => 'template',
                'template' => [
                    'name' => PlatformTemplateService::PAID,
                    'language' => ['code' => config('alerts.whatsapp_language')],
                    'components' => [['type' => 'body', 'parameters' => [
                        ['type' => 'text', 'text' => $merchant->name],
                        ['type' => 'text', 'text' => $until],
                    ]]],
                ],
            ]);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
