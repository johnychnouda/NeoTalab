<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\WhatsAppMessage;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends WhatsApp Cloud API messages on behalf of a merchant or the platform.
 */
class WhatsAppService
{
    public function graphUrl(string $path): string
    {
        $base = rtrim((string) config('whatsapp.graph_api_base'), '/');
        $version = config('whatsapp.graph_api_version');

        return "{$base}/{$version}/{$path}";
    }

    /**
     * @param  array<string,mixed>  $message  type: text|interactive|template
     * @return array<string,mixed>
     */
    public function sendToCustomer(Merchant $merchant, string $toPhone, array $message): array
    {
        $creds = MerchantWhatsAppCredentials::resolve($merchant);
        if (! $creds) {
            throw new RuntimeException('WhatsApp credentials are not configured for this merchant.');
        }

        return $this->send($creds['phone_id'], $creds['access_token'], $toPhone, $message, $merchant->id);
    }

    /**
     * Platform-owned number (billing reminders, welcomes from owner settings).
     *
     * @param  array<string,mixed>  $message
     * @return array<string,mixed>
     */
    public function sendPlatform(string $toPhone, array $message): array
    {
        $platform = PlatformSetting::singleton();
        $extra = $platform->settings ?? [];
        $phoneId = trim((string) ($extra['wa_phone_id'] ?? ''));
        $token = MerchantWhatsAppCredentials::platformAccessToken();

        if ($phoneId === '' || $token === '') {
            throw new RuntimeException('Platform WhatsApp credentials are not configured.');
        }

        return $this->send($phoneId, $token, $toPhone, $message, null);
    }

    /**
     * @param  array<string,mixed>  $message
     * @return array<string,mixed>
     */
    protected function send(
        string $phoneId,
        string $token,
        string $toPhone,
        array $message,
        ?string $merchantId,
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $toPhone),
            'type' => $message['type'] ?? 'text',
        ];

        if ($payload['type'] === 'text') {
            $payload['text'] = [
                'body' => (string) ($message['text'] ?? ''),
                'preview_url' => false,
            ];
        } elseif ($payload['type'] === 'interactive') {
            $payload['interactive'] = $message['interactive'] ?? [];
        } elseif ($payload['type'] === 'template') {
            $payload['template'] = $message['template'] ?? [];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($this->graphUrl("{$phoneId}/messages"), $payload);

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            Log::error('WhatsApp send failed', [
                'merchant_id' => $merchantId,
                'status' => $response->status(),
                'body' => $data,
            ]);

            throw new RuntimeException(
                'WhatsApp API error: '.($data['error']['message'] ?? $response->status())
            );
        }

        if ($merchantId) {
            WhatsAppMessage::create([
                'merchant_id' => $merchantId,
                'wa_message_id' => $data['messages'][0]['id'] ?? ('out_'.uniqid()),
                'direction' => 'outbound',
                'from_number' => $phoneId,
                'to_number' => $toPhone,
                'message_type' => $payload['type'],
                'payload' => $payload,
            ]);
        }

        return $data;
    }

    /**
     * @return array{success:bool,error?:string,display_phone_number?:string}
     */
    public function testCredentials(string $phoneId, string $token): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->get($this->graphUrl($phoneId), [
                'fields' => 'display_phone_number,verified_name,quality_rating',
            ]);

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            return [
                'success' => false,
                'error' => $data['error']['message'] ?? 'Meta API returned '.$response->status(),
            ];
        }

        return [
            'success' => true,
            'display_phone_number' => $data['display_phone_number'] ?? null,
            'verified_name' => $data['verified_name'] ?? null,
        ];
    }

    public function isValidSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if (! config('whatsapp.verify_signature')) {
            return true;
        }

        $secret = config('whatsapp.app_secret');
        if (! $secret) {
            return true;
        }

        if (! $signatureHeader || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
