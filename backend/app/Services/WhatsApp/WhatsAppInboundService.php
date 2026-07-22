<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Models\WhatsAppMessage;
use App\Services\ConversationService;
use App\Support\CurrentTenant;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound WhatsApp webhook payloads: idempotency, tenant binding, AI reply.
 */
class WhatsAppInboundService
{
    public function __construct(
        protected ConversationService $conversations,
        protected WhatsAppService $whatsapp,
        protected CurrentTenant $tenant,
    ) {}

    /**
     * @param  array<string,mixed>  $body  Raw Meta webhook JSON
     */
    public function handle(array $body): void
    {
        $entry = $body['entry'][0] ?? null;
        $change = $entry['changes'][0] ?? null;
        $value = $change['value'] ?? null;
        $messages = $value['messages'] ?? [];

        if (! is_array($messages) || $messages === []) {
            return;
        }

        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $merchant = MerchantWhatsAppCredentials::findMerchantByPhoneId($phoneNumberId);

        if (! $merchant) {
            Log::warning('WhatsApp inbound: unknown phone_number_id', ['phone_number_id' => $phoneNumberId]);

            return;
        }

        $this->tenant->set($merchant);

        try {
            foreach ($messages as $msg) {
                $this->processMessage($merchant, $msg, $phoneNumberId);
            }
        } finally {
            $this->tenant->forget();
        }
    }

    /**
     * @param  array<string,mixed>  $msg
     */
    protected function processMessage(Merchant $merchant, array $msg, string $phoneNumberId): void
    {
        $waMessageId = (string) ($msg['id'] ?? '');
        $fromPhone = '+'.ltrim((string) ($msg['from'] ?? ''), '+');

        if ($waMessageId === '' || $fromPhone === '+') {
            return;
        }

        if (WhatsAppMessage::query()->where('merchant_id', $merchant->id)->where('wa_message_id', $waMessageId)->exists()) {
            return;
        }

        WhatsAppMessage::create([
            'merchant_id' => $merchant->id,
            'wa_message_id' => $waMessageId,
            'direction' => 'inbound',
            'from_number' => $fromPhone,
            'to_number' => $phoneNumberId,
            'message_type' => $msg['type'] ?? 'text',
            'payload' => $msg,
        ]);

        $text = $this->extractText($msg);
        if ($text === null || trim($text) === '') {
            return;
        }

        $settings = $merchant->settings ?? [];
        $mode = $settings['mode'] ?? 'auto';
        if ($mode === 'closed') {
            $this->whatsapp->sendToCustomer($merchant, $fromPhone, [
                'type' => 'text',
                'text' => "Sorry, {$merchant->name} is currently closed. Please try again during business hours.",
            ]);

            return;
        }

        if ($mode === 'manual') {
            $this->whatsapp->sendToCustomer($merchant, $fromPhone, [
                'type' => 'text',
                'text' => 'A team member will reply to you shortly.',
            ]);

            return;
        }

        $conversation = $this->conversations->startOrResume([
            'phone' => $fromPhone,
            'locale' => $merchant->locale ?? 'en',
        ], 'whatsapp');

        $result = $this->conversations->processTurn($conversation, $text);
        $reply = trim($result['output']->assistantMessage);

        if ($reply !== '') {
            $this->whatsapp->sendToCustomer($merchant, $fromPhone, [
                'type' => 'text',
                'text' => $reply,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $msg
     */
    protected function extractText(array $msg): ?string
    {
        return match ($msg['type'] ?? '') {
            'text' => $msg['text']['body'] ?? null,
            'interactive' => $msg['interactive']['button_reply']['title']
                ?? $msg['interactive']['list_reply']['title']
                ?? null,
            'button' => $msg['button']['text'] ?? $msg['button']['payload'] ?? null,
            default => null,
        };
    }
}
