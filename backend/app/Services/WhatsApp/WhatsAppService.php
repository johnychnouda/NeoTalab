<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\WhatsAppMessage;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     * @param  array<string,mixed>  $message  type: text|interactive|template|location
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
        $token = MerchantWhatsAppCredentials::platformSenderToken();

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
        } elseif ($payload['type'] === 'location') {
            $payload['location'] = $message['location'] ?? [];
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

        // The message is already delivered: logging it must never fail the caller, or a job
        // retry would send it to the customer a second time.
        if ($merchantId) {
            try {
                WhatsAppMessage::create([
                    'merchant_id' => $merchantId,
                    'wa_message_id' => $data['messages'][0]['id'] ?? ('out_'.Str::uuid()),
                    'direction' => 'outbound',
                    'from_number' => $phoneId,
                    'to_number' => $toPhone,
                    'message_type' => $payload['type'],
                    'payload' => $payload,
                ]);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp outbound log failed', ['merchant_id' => $merchantId, 'error' => $e->getMessage()]);
            }
        }

        return $data;
    }

    /**
     * Downloads a media file a customer sent to the merchant's number (two steps: Meta gives
     * a short-lived URL for the media id, which needs the same token to fetch).
     *
     * @return array{body: string, mime_type: string}|null null when missing, too big or unavailable
     */
    public function downloadMedia(Merchant $merchant, string $mediaId, int $maxBytes): ?array
    {
        $creds = MerchantWhatsAppCredentials::resolve($merchant);
        if (! $creds || $mediaId === '') {
            return null;
        }

        $meta = Http::withToken($creds['access_token'])->acceptJson()->get($this->graphUrl($mediaId));
        $url = (string) $meta->json('url', '');
        if (! $meta->successful() || $url === '' || (int) $meta->json('file_size', 0) > $maxBytes) {
            Log::warning('WhatsApp media lookup failed', ['merchant_id' => $merchant->id, 'status' => $meta->status()]);

            return null;
        }

        $file = Http::withToken($creds['access_token'])->timeout(30)->get($url);
        if (! $file->successful() || $file->body() === '' || strlen($file->body()) > $maxBytes) {
            Log::warning('WhatsApp media download failed', ['merchant_id' => $merchant->id, 'status' => $file->status()]);

            return null;
        }

        return ['body' => $file->body(), 'mime_type' => (string) $meta->json('mime_type', $file->header('Content-Type'))];
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
        // Signature checks may only be relaxed outside production, a missing secret in
        // production must reject, otherwise anyone could forge customer messages.
        if (! config('whatsapp.verify_signature') && ! app()->isProduction()) {
            return true;
        }

        $secret = config('whatsapp.app_secret');
        if (! $secret) {
            if (app()->isProduction()) {
                Log::critical('WhatsApp webhook rejected: WHATSAPP_APP_SECRET is not configured.');

                return false;
            }

            return true;
        }

        if (! $signatureHeader || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
