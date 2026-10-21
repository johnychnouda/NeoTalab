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

    public function __construct(protected WhatsAppService $whatsapp) {}

    /**
     * @return array<string, array{body: string, example: list<string>, buttons?: list<string>}>
     */
    public static function definitions(): array
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
            self::REFUND_NEEDED => [
                'body' => 'Refund needed at {{1}}: order #{{2}} was paid with Whish ({{3}}) but it was cancelled. Customer: {{4}}. Please send the money back from your Whish account.',
                'example' => ['Beirut Bites', '128', '12.00 USD', '+96170123456'],
            ],
        ];
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
    public static function message(string $name, array $params, array $buttonPayloads = []): array
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
                'language' => ['code' => 'en'],
                'components' => $components,
            ],
        ];
    }

    /**
     * @param  array{body: string, example: list<string>, buttons?: list<string>}  $def
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
            'language' => 'en',
            'category' => 'UTILITY',
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
