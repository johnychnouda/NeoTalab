<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Message templates NeoTalab needs on every shop's WhatsApp Business Account, so the shop's own
 * number can message the owner's personal number outside Meta's 24-hour window (the subscription reminder), and its customers
 * (see CustomerTemplates). Submitted automatically after a shop connects; statuses are refreshed
 * by `templates:sync`. State lives in merchants.settings.bot.templates.{name} = {id, status}.
 */
class ShopTemplateService
{
    public const BILLING_REMINDER = 'neotalab_billing_reminder';

    /** The reminder in Arabic and French: the same variables, in the same order, as the English one. */
    private const BILLING_TEXTS = [
        'ar' => 'مرحباً {{1}}، ينتهي اشتراكك في NeoTalab بتاريخ {{2}}. يرجى التجديد ليبقى بوت الطلبات على واتساب يعمل. ردّ هنا إذا احتجت إلى مساعدة.',
        'fr' => "Bonjour {{1}}, votre abonnement NeoTalab se termine le {{2}}. Merci de le renouveler pour que votre bot de commandes WhatsApp continue de fonctionner. Répondez ici si vous avez besoin d'aide.",
    ];

    public function __construct(protected WhatsAppService $whatsapp) {}

    /**
     * @return array<string, array{body: string, example: list<string>, buttons?: list<string>}>
     */
    public static function definitions(): array
    {
        $english = self::ownerDefinitions();
        $translated = [];
        foreach (self::BILLING_TEXTS as $language => $body) {
            $translated[self::BILLING_REMINDER."_{$language}"] = [
                'body' => $body,
                'example' => $english[self::BILLING_REMINDER]['example'],
                'language' => $language,
            ];
        }

        return [
            ...$english,
            ...$translated,
            ...CustomerTemplates::definitions(),
        ];
    }

    /**
     * @return array<string, array{body: string, example: list<string>, buttons?: list<string>}>
     */
    protected static function ownerDefinitions(): array
    {
        return [
            self::BILLING_REMINDER => [
                'body' => 'Hello {{1}}, your NeoTalab subscription ends on {{2}}. Please renew to keep your WhatsApp ordering bot running. Reply here if you need help.',
                'example' => ['Beirut Bites', '2026-10-10'],
            ],
        ];
    }

    /**
     * The approved version of an owner template to use for this shop: in the shop's language, else English.
     *
     * @return array{name: string, language: string}|null null when none is approved yet
     */
    public function resolve(Merchant $merchant, string $base, ?string $locale = null): ?array
    {
        $locale ??= $merchant->locale;
        $names = in_array($locale, ['ar', 'fr'], true) ? ["{$base}_{$locale}", $base] : [$base];
        foreach ($names as $name) {
            if ($this->isApproved($merchant, $name)) {
                return ['name' => $name, 'language' => self::definitions()[$name]['language'] ?? 'en'];
            }
        }

        return null;
    }

    public function isApproved(Merchant $merchant, string $name): bool
    {
        return (($merchant->settings ?? [])['bot']['templates'][$name]['status'] ?? null) === 'APPROVED';
    }

    /**
     * Submit missing (or rejected) templates and refresh the status of the others.
     */
    public function sync(Merchant $merchant): void
    {
        $bot = ($merchant->settings ?? [])['bot'] ?? [];
        $wabaId = trim((string) ($bot['waba_id'] ?? ''));
        $creds = MerchantWhatsAppCredentials::resolve($merchant);
        if ($wabaId === '' || ! $creds) {
            return;
        }

        $templates = $bot['templates'] ?? [];

        foreach (self::definitions() as $name => $def) {
            $status = $templates[$name]['status'] ?? null;

            if ($status === null || $status === 'REJECTED') {
                $templates[$name] = $this->submit($wabaId, $creds['access_token'], $name, $def) ?? $templates[$name] ?? ['status' => null];
            } elseif ($status !== 'APPROVED') {
                $templates[$name]['status'] = $this->fetchStatus($wabaId, $creds['access_token'], $name) ?? $status;
            }
        }

        $settings = $merchant->settings ?? [];
        $settings['bot']['templates'] = $templates;
        $merchant->forceFill(['settings' => $settings])->save();
    }

    /**
     * Template message payload for WhatsAppService.
     *
     * @param  list<string>  $params
     * @param  list<string>  $buttonPayloads  one per quick-reply button, in order (comes back as the tap's payload)
     * @return array<string, mixed>
     */
    public static function message(string $name, array $params, array $buttonPayloads = [], string $language = 'en'): array
    {
        $components = [[
            'type' => 'body',
            'parameters' => array_map(fn (string $v) => ['type' => 'text', 'text' => $v === '' ? '—' : $v], $params),
        ]];
        foreach (array_values($buttonPayloads) as $i => $payload) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) $i,
                'parameters' => [['type' => 'payload', 'payload' => $payload]],
            ];
        }

        return [
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ];
    }

    /**
     * @param  array{body: string, example: list<string>, buttons?: list<string>, language?: string}  $def
     * @return array{id: ?string, status: ?string}|null
     */
    protected function submit(string $wabaId, string $token, string $name, array $def): ?array
    {
        $components = [[
            'type' => 'BODY',
            'text' => $def['body'],
            'example' => ['body_text' => [$def['example']]],
        ]];
        if (! empty($def['buttons'])) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => array_map(fn (string $text) => ['type' => 'QUICK_REPLY', 'text' => $text], $def['buttons']),
            ];
        }

        $response = Http::withToken($token)->acceptJson()->post($this->whatsapp->graphUrl("{$wabaId}/message_templates"), [
            'name' => $name,
            'language' => $def['language'] ?? 'en',
            'category' => 'UTILITY',
            // Meta may file one (e.g. the renewal reminder) under Marketing; that is allowed so it still gets approved.
            'allow_category_change' => true,
            'components' => $components,
        ]);

        if (! $response->successful()) {
            // Already exists on this account (e.g. re-connect), pick up its current status instead.
            $status = $this->fetchStatus($wabaId, $token, $name);
            if ($status !== null) {
                return ['id' => null, 'status' => $status];
            }

            Log::warning('Template submission failed', ['waba_id' => $wabaId, 'template' => $name, 'body' => $response->json()]);

            return null;
        }

        return ['id' => $response->json('id'), 'status' => $response->json('status', 'PENDING')];
    }

    protected function fetchStatus(string $wabaId, string $token, string $name): ?string
    {
        $response = Http::withToken($token)->acceptJson()
            ->get($this->whatsapp->graphUrl("{$wabaId}/message_templates"), ['name' => $name, 'fields' => 'name,status']);

        $match = collect($response->json('data') ?? [])->firstWhere('name', $name);

        return $match['status'] ?? null;
    }
}
