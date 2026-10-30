<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformSetting;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The templates the platform number sends: problem alerts ("system_alert") and good news such as a
 * new join request ("neotalab_update") to the owner, and login details to approved shops. Submitted
 * when the number connects and checked hourly by templates:sync; until a template is approved the
 * old way is used (the alert template, or plain text).
 */
class PlatformTemplateService
{
    public function __construct(protected WhatsAppService $whatsapp) {}

    // _v4: Meta rejected the earlier versions within seconds (the code, then the login link, then any mention of codes and passwords).
    // The login is a button, the text has only the shop name and email, and the code goes alone in CODE.
    public const WELCOME = 'neotalab_welcome_v4';

    public const ACCESS = 'neotalab_access_v4';

    /** The login code on its own, in Meta's Authentication format (its wording is fixed by Meta; the shop taps "Copy code"). */
    public const CODE = 'neotalab_code';

    public const PAID = 'neotalab_paid';

    /** The nightly report to each shop's owner, sent from NeoTalab's own number. */
    public const DAILY_REPORT = 'neotalab_daily_report';

    /** @return array<string, array{text: string, examples: list<string>}> */
    public static function definitions(): array
    {
        return [
            config('alerts.whatsapp_template') => [
                'text' => 'Account notice for your NeoTalab platform: {{1}}. Please review it in your owner portal under Inbox → System.',
                'examples' => ['the nightly database backup failed'],
            ],
            config('alerts.notice_template') => [
                'text' => '🎉 Good news from NeoTalab: {{1}}. Tap the link to open it in your owner portal.',
                'examples' => ['New shop request: Abou Ali Snack (Restaurant, Beirut). Contact: Ali +96170000000. Review it: https://neotalab.com/owner?req=1'],
            ],
            // Login details for a newly approved shop, which has never written to the platform number. The code follows in CODE.
            self::WELCOME => [
                'text' => "Welcome to NeoTalab! 🎉 Your account for {{1}} is ready.\n\nYour email address is {{2}}\n\nTap the button below to open your dashboard. Your access details follow in the next message.",
                'examples' => ['Abou Ali Snack', 'abouali@merchant.neotalab'],
                'login_button' => true,
            ],
            self::CODE => ['authentication' => true],
            // Sent to a shop owner when a subscription payment is recorded.
            self::PAID => [
                'text' => 'Thank you! Your NeoTalab subscription for {{1}} is paid until {{2}}. Thank you for trusting NeoTalab.',
                'examples' => ['Abou Ali Snack', '21 November 2026'],
            ],
            self::DAILY_REPORT => [
                'text' => "📊 Here is the daily report for {{1}} on {{2}}, sent by NeoTalab.\n\nOrders delivered today: {{3}}\nTotal sales today: {{4}}\nAverage customer rating: {{5}}\nOrders that were late: {{6}}\nBest selling items today: {{7}}\n\nOpen your dashboard at any time to see more details about your shop and your customers.",
                'examples' => ['Beirut Bites', '2026-10-06', '42', '512.00 USD', '4.6 / 5', '2', 'Shawarma ×30, Fries ×18'],
            ],
            self::ACCESS => [
                'text' => "Here is how to get back into your NeoTalab account for {{1}}.\n\nYour email address is {{2}}\n\nTap the button below to open your dashboard. Your new access details follow in the next message.",
                'examples' => ['Abou Ali Snack', 'abouali@merchant.neotalab'],
                'login_button' => true,
            ],
        ];
    }

    public function approved(string $name): bool
    {
        return (((PlatformSetting::singleton()->settings ?? [])['wa_templates'] ?? [])[$name] ?? null) === 'APPROVED';
    }

    /** The template a good-news message goes out with: its own once approved, else the alert one. */
    public function noticeTemplate(): string
    {
        return $this->approved(config('alerts.notice_template')) ? config('alerts.notice_template') : config('alerts.whatsapp_template');
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
        // A login code uses Meta's own format: Meta writes the text, the shop gets a "Copy code" button.
        $payload = isset($def['authentication'])
            ? [
                'name' => $name,
                'language' => config('alerts.whatsapp_language'),
                'category' => 'AUTHENTICATION',
                'components' => [
                    ['type' => 'BODY', 'add_security_recommendation' => true],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE']]],
                ],
            ]
            // Meta may file it under another category; that is allowed so it still gets approved.
            : [
                'name' => $name,
                'language' => config('alerts.whatsapp_language'),
                'category' => 'UTILITY',
                'allow_category_change' => true,
                'components' => array_values(array_filter([
                    [
                        'type' => 'BODY',
                        'text' => $def['text'],
                        'example' => ['body_text' => [$def['examples']]],
                    ],
                    // The login page is a button whose address ends in the shop's slug, never a link typed into the text.
                    isset($def['login_button']) ? ['type' => 'BUTTONS', 'buttons' => [[
                        'type' => 'URL',
                        'text' => 'Open my dashboard',
                        'url' => rtrim((string) config('app.frontend_url'), '/').'/backoffice/{{1}}',
                        'example' => [rtrim((string) config('app.frontend_url'), '/').'/backoffice/abou-ali-snack'],
                    ]]] : null,
                ])),
            ];
        $response = Http::withToken($token)->acceptJson()->post($this->whatsapp->graphUrl("{$wabaId}/message_templates"), $payload);

        if (! $response->successful()) {
            Log::warning('Platform template submission failed', ['template' => $name, 'body' => $response->json()]);

            return null;
        }

        return $response->json('status') ?? 'PENDING';
    }
}
