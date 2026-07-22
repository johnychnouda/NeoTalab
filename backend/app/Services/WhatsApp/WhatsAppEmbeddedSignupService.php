<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Services\MerchantBotService;
use App\Support\MerchantProfileSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Meta WhatsApp Embedded Signup (OAuth code exchange + WABA subscription).
 */
class WhatsAppEmbeddedSignupService
{
    public function __construct(
        protected MerchantBotService $bots,
        protected WhatsAppService $whatsapp,
    ) {}

    /**
     * @return array{enabled:bool,appId:?string,configId:?string,graphVersion:string}
     */
    public function config(): array
    {
        $appId = trim((string) config('whatsapp.meta_app_id'));
        $configId = trim((string) config('whatsapp.embedded_signup_config_id'));

        return [
            'enabled' => $appId !== '' && $configId !== '',
            'appId' => $appId !== '' ? $appId : null,
            'configId' => $configId !== '' ? $configId : null,
            'graphVersion' => (string) config('whatsapp.graph_api_version'),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function complete(Merchant $merchant, array $payload): Merchant
    {
        $code = trim((string) ($payload['code'] ?? ''));
        $phoneNumberId = trim((string) ($payload['phoneNumberId'] ?? $payload['phone_number_id'] ?? ''));
        $wabaId = trim((string) ($payload['wabaId'] ?? $payload['waba_id'] ?? ''));

        if ($code === '' || $phoneNumberId === '' || $wabaId === '') {
            throw ValidationException::withMessages([
                'code' => ['Embedded signup did not return complete data. Try again.'],
            ]);
        }

        $accessToken = $this->exchangeCode($code);
        $this->subscribeWaba($wabaId, $accessToken);

        $merchant = $this->bots->applyEmbeddedSignup($merchant, [
            'phoneNumberId' => $phoneNumberId,
            'accessToken' => $accessToken,
            'wabaId' => $wabaId,
        ]);

        $settings = $merchant->settings ?? [];
        $display = ($settings['bot'] ?? [])['display_phone_number'] ?? null;
        if ($display) {
            MerchantProfileSync::applyWhatsapp($merchant, $settings, $display);
            $merchant->settings = $settings;
            $merchant->save();
        }

        return $merchant->fresh();
    }

    protected function exchangeCode(string $code): string
    {
        $appId = config('whatsapp.meta_app_id');
        $secret = config('whatsapp.app_secret');

        if (! $appId || ! $secret) {
            throw ValidationException::withMessages([
                'code' => ['WhatsApp Meta app credentials are not configured on the server.'],
            ]);
        }

        $response = Http::get($this->whatsapp->graphUrl('oauth/access_token'), [
            'client_id' => $appId,
            'client_secret' => $secret,
            'code' => $code,
        ]);

        $data = $response->json() ?? [];
        if (! $response->successful() || empty($data['access_token'])) {
            $message = $data['error']['message'] ?? 'Failed to exchange signup code';

            throw ValidationException::withMessages([
                'code' => [$message],
            ]);
        }

        return (string) $data['access_token'];
    }

    protected function subscribeWaba(string $wabaId, string $accessToken): void
    {
        $response = Http::withToken($accessToken)
            ->post($this->whatsapp->graphUrl("{$wabaId}/subscribed_apps"));

        if (! $response->successful()) {
            $data = $response->json() ?? [];
            $message = $data['error']['message'] ?? 'Failed to subscribe app to WABA';

            throw ValidationException::withMessages([
                'wabaId' => [$message],
            ]);
        }
    }
}
