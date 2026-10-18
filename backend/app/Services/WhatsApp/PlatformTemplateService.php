<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformSetting;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The templates the platform number sends to the owner: problem alerts ("system_alert") and good
 * news such as a new join request ("neotalab_update"). Submitted when the number connects and
 * checked hourly by templates:sync; a notice uses the alert template until its own is approved.
 */
class PlatformTemplateService
{
    public function __construct(protected WhatsAppService $whatsapp) {}

    /** @return array<string, array{text: string, example: string}> */
    public static function definitions(): array
    {
        return [
            config('alerts.whatsapp_template') => [
                'text' => 'Account notice for your NeoTalab platform: {{1}}. Please review it in your owner portal under Inbox → System.',
                'example' => 'the nightly database backup failed',
            ],
            config('alerts.notice_template') => [
                'text' => '🎉 Good news from NeoTalab: {{1}}. Tap the link to open it in your owner portal.',
                'example' => 'New shop request: Abou Ali Snack (Restaurant, Beirut). Contact: Ali +96170000000. Review it: https://neotalab.com/owner?req=1',
            ],
        ];
    }

    /** The template a good-news message goes out with: its own once approved, else the alert one. */
    public function noticeTemplate(): string
    {
        $status = ((PlatformSetting::singleton()->settings ?? [])['wa_templates'] ?? [])[config('alerts.notice_template')] ?? null;

        return $status === 'APPROVED' ? config('alerts.notice_template') : config('alerts.whatsapp_template');
    }

    /** Right after the platform number connects. */
    public function submitAll(string $wabaId, string $token): void
    {
        foreach (array_keys(self::definitions()) as $name) {
            $this->submit($wabaId, $token, $name);
        }
    }

    /** Hourly: submit what is missing or rejected, and remember each template's status. */
    public function sync(): void
    {
        $platform = PlatformSetting::singleton();
        $settings = $platform->settings ?? [];
        $wabaId = trim((string) ($settings['wa_waba_id'] ?? ''));
        $token = MerchantWhatsAppCredentials::platformSenderToken();
        if ($wabaId === '' || $token === '') {
            return;
        }

        $statuses = $settings['wa_templates'] ?? [];
        foreach (array_keys(self::definitions()) as $name) {
            $status = Http::withToken($token)->acceptJson()
                ->get($this->whatsapp->graphUrl("{$wabaId}/message_templates"), ['name' => $name, 'fields' => 'name,status'])
                ->json('data.0.status');
            if ($status === null || $status === 'REJECTED') {
                $status = $this->submit($wabaId, $token, $name) ?? $status;
            }
            $statuses[$name] = $status;
        }

        $settings = $platform->fresh()->settings ?? [];
        $settings['wa_templates'] = $statuses;
        $platform->forceFill(['settings' => $settings])->save();
    }

    protected function submit(string $wabaId, string $token, string $name): ?string
    {
        $def = self::definitions()[$name];
        // Meta may file it under another category; that is allowed so it still gets approved.
        $response = Http::withToken($token)->acceptJson()->post($this->whatsapp->graphUrl("{$wabaId}/message_templates"), [
            'name' => $name,
            'language' => config('alerts.whatsapp_language'),
            'category' => 'UTILITY',
            'allow_category_change' => true,
            'components' => [[
                'type' => 'BODY',
                'text' => $def['text'],
                'example' => ['body_text' => [[$def['example']]]],
            ]],
        ]);

        if (! $response->successful()) {
            Log::warning('Platform template submission failed', ['template' => $name, 'body' => $response->json()]);

            return null;
        }

        return $response->json('status') ?? 'PENDING';
    }
}
