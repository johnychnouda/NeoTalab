<?php

namespace App\Services;

use App\Models\Merchant;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Validation\ValidationException;

/**
 * Merchant WhatsApp bot credentials, health checks, and webhook registration.
 */
class MerchantBotService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateBot(Merchant $merchant, array $payload): Merchant
    {
        $phoneId = trim((string) ($payload['phoneNumberId'] ?? $payload['phone_number_id'] ?? ''));
        $token = trim((string) ($payload['accessToken'] ?? $payload['access_token'] ?? ''));
        $expires = $payload['tokenExpires'] ?? $payload['token_expires'] ?? null;

        if ($phoneId === '') {
            throw ValidationException::withMessages([
                'phoneNumberId' => ['Phone Number ID is required.'],
            ]);
        }

        if ($token === '' && MerchantWhatsAppCredentials::platformAccessToken() === '') {
            throw ValidationException::withMessages([
                'accessToken' => ['Access token is required when no platform WhatsApp token is configured.'],
            ]);
        }

        $settings = $merchant->settings ?? [];
        $bot = $settings['bot'] ?? [];
        $bot['phone_id'] = $phoneId;
        if ($token !== '') {
            $bot['access_token'] = $token;
        } elseif (! isset($bot['access_token'])) {
            unset($bot['access_token']);
        }
        $bot['token_expires'] = $expires ?: null;
        $bot['connected_via'] = 'manual';
        $bot['status'] = 'inactive';
        $bot['error'] = null;
        $bot['last_checked'] = now()->toIso8601String();
        $settings['bot'] = $bot;
        $merchant->settings = $settings;
        $merchant->save();

        $this->testBot($merchant->fresh());

        return $merchant->fresh();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function applyEmbeddedSignup(Merchant $merchant, array $payload): Merchant
    {
        $phoneId = trim((string) ($payload['phoneNumberId'] ?? $payload['phone_number_id'] ?? ''));
        $token = trim((string) ($payload['accessToken'] ?? $payload['access_token'] ?? ''));
        $wabaId = trim((string) ($payload['wabaId'] ?? $payload['waba_id'] ?? ''));

        if ($phoneId === '' || $token === '') {
            throw ValidationException::withMessages([
                'phoneNumberId' => ['Embedded signup did not return complete credentials. Try again.'],
            ]);
        }

        $settings = $merchant->settings ?? [];
        $bot = $settings['bot'] ?? [];
        $bot['phone_id'] = $phoneId;
        $bot['access_token'] = $token;
        $bot['waba_id'] = $wabaId !== '' ? $wabaId : ($bot['waba_id'] ?? null);
        $bot['connected_via'] = 'embedded_signup';
        $bot['token_expires'] = null;
        $bot['status'] = 'inactive';
        $bot['error'] = null;
        $bot['last_checked'] = now()->toIso8601String();
        $settings['bot'] = $bot;
        $merchant->settings = $settings;
        $merchant->save();

        $this->testBot($merchant->fresh());

        return $merchant->fresh();
    }

    /**
     * @return array{success:bool,error?:string,display_phone_number?:string}
     */
    public function testBot(Merchant $merchant): array
    {
        $creds = MerchantWhatsAppCredentials::resolve($merchant);
        $bot = ($merchant->settings ?? [])['bot'] ?? [];
        $expires = $bot['token_expires'] ?? null;
        $settings = $merchant->settings ?? [];

        if (! $creds) {
            $settings['bot'] = array_merge($bot, [
                'status' => 'error',
                'error' => 'Missing Phone Number ID or access token (merchant or platform).',
                'last_checked' => now()->toIso8601String(),
            ]);
            $merchant->settings = $settings;
            $merchant->save();

            return ['success' => false, 'error' => 'Missing Phone Number ID or access token (merchant or platform).'];
        }

        if ($expires && now()->parse($expires)->isPast()) {
            $settings['bot'] = array_merge($bot, [
                'status' => 'error',
                'error' => 'Token expired — reconnect via Meta Embedded Signup.',
                'last_checked' => now()->toIso8601String(),
            ]);
            $merchant->settings = $settings;
            $merchant->save();

            return ['success' => false, 'error' => 'Token expired — reconnect via Meta Embedded Signup.'];
        }

        $result = $this->whatsapp->testCredentials($creds['phone_id'], $creds['access_token']);

        $settings['bot'] = array_merge($bot, [
            'status' => $result['success'] ? 'active' : 'error',
            'error' => $result['success'] ? null : ($result['error'] ?? 'Connection failed'),
            'last_checked' => now()->toIso8601String(),
            'display_phone_number' => $result['display_phone_number'] ?? null,
        ]);
        $merchant->settings = $settings;
        $merchant->save();

        return $result;
    }

    /**
     * @return array{webhook_url:string,verify_token:string,message:string}
     */
    public function registerWebhook(Merchant $merchant): array
    {
        $this->touchBotStatus($merchant, ($merchant->settings['bot']['status'] ?? 'inactive'), null);

        return [
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/webhooks/whatsapp',
            'verify_token' => MerchantWhatsAppCredentials::verifyToken(),
            'message' => 'Register this webhook URL once in Meta App Dashboard → WhatsApp → Configuration. Routing uses each merchant Phone Number ID.',
        ];
    }

    public function restartBot(Merchant $merchant): Merchant
    {
        $this->testBot($merchant);

        return $merchant->fresh();
    }

    protected function touchBotStatus(Merchant $merchant, string $status, ?string $error): Merchant
    {
        $settings = $merchant->settings ?? [];
        $bot = $settings['bot'] ?? [];
        $bot['status'] = $status;
        $bot['error'] = $error;
        $bot['last_checked'] = now()->toIso8601String();
        $settings['bot'] = $bot;
        $merchant->settings = $settings;
        $merchant->save();

        return $merchant->fresh();
    }
}
