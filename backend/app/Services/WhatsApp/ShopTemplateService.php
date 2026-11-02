<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Message templates NeoTalab needs on every shop's WhatsApp Business Account, so the shop's own
 * number can message the owner's personal number and the staff outside Meta's 24-hour window
 * (nightly report, subscription reminder, new-order tickets, "a customer needs you" alerts). Submitted automatically after a shop connects; statuses are refreshed
 * by `templates:sync`. State lives in merchants.settings.bot.templates.{name} = {id, status}.
 */
class ShopTemplateService
{
    public const BILLING_REMINDER = 'neotalab_billing_reminder';

    /** Staff ticket for an order waiting to be accepted: buttons Accept / Can't take it. */
    public const NEW_ORDER = 'neotalab_new_order';

    /** Staff alert when a customer needs a person (asked for one, or sent a photo): button Back to bot. */
    public const STAFF_ALERT = 'neotalab_staff_alert';

    /** Staff alert for a new booking: buttons Done / No-show (tapped after the visit). */
    public const NEW_BOOKING = 'neotalab_new_booking';

    /** Days shops: an order is due today, buttons Ready / on its way, Delay 1 day. */
    public const ORDER_DUE = 'neotalab_order_due';

    /** A Whish payment arrived after the order was cancelled: the shop must refund it. */
    public const REFUND_NEEDED = 'neotalab_refund_needed';

    /** A customer says they paid by Whish: the shop checks its Whish account; buttons Received / Not received. */
    public const PAYMENT_CHECK = 'neotalab_payment_check';

    /** Staff: the customer cancelled an order they may already be preparing. */
    public const CUSTOMER_CANCELLED = 'neotalab_customer_cancelled';

    /** Staff: an order for a chosen time is due now. */
    public const SCHEDULED_DUE = 'neotalab_scheduled_due';

    /** Staff: the customer cancelled a booking. */
    public const BOOKING_CANCELLED = 'neotalab_booking_cancelled';

    /** Staff: a delivery failed. */
    public const NOT_DELIVERED = 'neotalab_not_delivered';

    /** Staff: an accepted order was cancelled because the customer never paid with Whish. */
    public const WHISH_UNPAID = 'neotalab_whish_unpaid';

    /** Staff: a customer rated an order 1 to 3. */
    public const LOW_RATING = 'neotalab_low_rating';

    /** Staff: a customer asked for a price. */
    public const QUOTE_REQUEST = 'neotalab_quote_request';

    /** Staff: a customer declined a price. */
    public const QUOTE_DECLINED = 'neotalab_quote_declined';

    public function __construct(protected WhatsAppService $whatsapp) {}

    /**
     * @return array<string, array{body: string, example: list<string>, buttons?: list<string>}>
     */
    public static function definitions(): array
    {
        $english = self::staffDefinitions();
        $translated = [];
        foreach (StaffTemplateTexts::all() as $base => $languages) {
            foreach ($languages as $language => $def) {
                $translated["{$base}_{$language}"] = [
                    'body' => $def['body'],
                    'example' => $english[$base]['example'],
                    'language' => $language,
                ] + (isset($def['buttons']) ? ['buttons' => $def['buttons']] : []);
            }
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
    protected static function staffDefinitions(): array
    {
        return [
            self::BILLING_REMINDER => [
                'body' => 'Hello {{1}}, your NeoTalab subscription ends on {{2}}. Please renew to keep your WhatsApp ordering bot running. Reply here if you need help.',
                'example' => ['Beirut Bites', '2026-10-10'],
            ],
            self::NEW_ORDER => [
                'body' => "New order #{{1}} for {{2}}: {{3}}. Usual time: {{4}}. Tap Accept to confirm it to the customer, or Can't take it to refuse it.",
                'example' => ['128', 'Beirut Bites', '2× Shawarma · Total 12.00 USD (cash) · Hamra, Bliss St · +96170123456', '25 min'],
                'buttons' => ['Accept', "Can't take it"],
            ],
            self::STAFF_ALERT => [
                'body' => 'A customer needs a person at {{1}}: {{2}}. The assistant is paused in that chat, so please answer them from the WhatsApp Business app. Tap Back to bot when you are done.',
                'example' => ['Beirut Bites', '+96170123456 sent a photo'],
                'buttons' => ['Back to bot'],
            ],
            self::NEW_BOOKING => [
                'body' => 'New booking at {{1}}: {{2}} on {{3}} at {{4}}, price {{5}}. Customer: {{6}}. After the visit, tap Done or No-show.',
                'example' => ['Glow Salon', 'Haircut', 'Wed 14 Oct', '15:30', '20.00 USD', '+96170123456'],
                'buttons' => ['Done', 'No-show'],
            ],
            self::ORDER_DUE => [
                'body' => 'Order #{{1}} at {{2}} is due today: {{3}}. Tap Ready / on its way when it leaves, or Delay 1 day to move it to the next working day.',
                'example' => ['57', 'Gadget Hub', '1× Phone case · Total 15.00 USD (cash) · Jbeil, Main St · +96170123456'],
                'buttons' => ['Ready / on its way', 'Delay 1 day'],
            ],
            self::PAYMENT_CHECK => [
                'body' => 'Payment to check for order #{{1}}: {{2}} from {{3}}. Look for the note {{4}} in your Whish account. Did the money arrive? Tap Received or Not received.',
                'example' => ['128', '12.00$', '+96170123456', '128'],
                'buttons' => ['Received', 'Not received'],
            ],
            self::CUSTOMER_CANCELLED => [
                'body' => 'Order #{{1}} at {{2}} was cancelled by the customer ({{3}}). Please do not prepare it.',
                'example' => ['57', 'Beirut Bites', '+96170123456'],
            ],
            self::SCHEDULED_DUE => [
                'body' => 'Order #{{1}} at {{2}} is due {{3}}. Please start preparing it now.',
                'example' => ['57', 'Flower Shop', 'Friday 10:00–12:00'],
            ],
            self::BOOKING_CANCELLED => [
                'body' => 'A booking at {{1}} was cancelled by the customer: {{2}} on {{3}} at {{4}}. Customer: {{5}}. That time is free again.',
                'example' => ['Glow Salon', 'Haircut', 'Wed 14 Oct', '15:30', '+96170123456'],
            ],
            self::NOT_DELIVERED => [
                'body' => 'Order #{{1}} at {{2}} could not be delivered. Reason: {{3}}. The customer has been told.',
                'example' => ['57', 'Gadget Hub', 'nobody answered the door'],
            ],
            self::WHISH_UNPAID => [
                'body' => 'Order #{{1}} at {{2}} was cancelled because the customer did not pay with Whish. Please do not prepare it.',
                'example' => ['57', 'Beirut Bites'],
            ],
            self::LOW_RATING => [
                'body' => 'Order #{{1}} at {{2}} was rated {{3}} out of 5 by {{4}}. Please call the customer to find out what went wrong.',
                'example' => ['57', 'Beirut Bites', '2', '+96170123456'],
            ],
            self::QUOTE_REQUEST => [
                'body' => 'New price request #{{1}} at {{2}} from {{3}}: {{4}}. To answer, send: quote {{5}}, then the price and what the job is.',
                'example' => ['12', 'Quick Fix', '+96170123456', 'Screen replacement for my iPhone 12', '12'],
            ],
            self::QUOTE_DECLINED => [
                'body' => 'Quote #{{1}} at {{2}} was declined by the customer ({{3}}). No further action is needed.',
                'example' => ['12', 'Quick Fix', '+96170123456'],
            ],
            self::REFUND_NEEDED => [
                'body' => 'Refund needed at {{1}}: order #{{2}} was paid with Whish ({{3}}) but it was cancelled. Customer: {{4}}. Please send the money back from your Whish account.',
                'example' => ['Beirut Bites', '128', '12.00 USD', '+96170123456'],
            ],
        ];
    }

    /**
     * The approved version of a staff or owner template to use for this shop: in the shop's language, else English.
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
