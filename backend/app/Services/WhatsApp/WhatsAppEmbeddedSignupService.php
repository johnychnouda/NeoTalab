<?php

namespace App\Services\WhatsApp;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Services\MerchantBotService;
use App\Support\MerchantProfileSync;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'app_id' => $appId !== '' ? $appId : null,
            'configId' => $configId !== '' ? $configId : null,
            'config_id' => $configId !== '' ? $configId : null,
            'graphVersion' => (string) config('whatsapp.graph_api_version'),
            'graph_version' => (string) config('whatsapp.graph_api_version'),
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

        $coexistence = (bool) ($payload['coexistence'] ?? false);

        $accessToken = $this->exchangeCode($code);
        $this->subscribeWaba($wabaId, $accessToken);

        // Coexistence (number stays in the WhatsApp Business app): Meta requires both syncs to be
        // started within 24h of onboarding; the number is already registered, so no /register.
        // A new number must be registered for Cloud API messaging, or every send fails.
        $pin = null;
        if ($coexistence) {
            $this->startCoexistenceSync($phoneNumberId, $accessToken);
        } else {
            $pin = $this->registerNumber($phoneNumberId, $accessToken);
        }

        $merchant = $this->bots->applyEmbeddedSignup($merchant, [
            'phoneNumberId' => $phoneNumberId,
            'accessToken' => $accessToken,
            'wabaId' => $wabaId,
        ]);

        $settings = $merchant->settings ?? [];
        $settings['bot']['coexistence'] = $coexistence;
        if ($pin !== null) {
            // The number's two-step verification PIN: needed to re-register or move the number later.
            $settings['bot']['registration_pin'] = Crypt::encryptString($pin);
        }
        $merchant->settings = $settings;
        $merchant->save();
        $display = ($settings['bot'] ?? [])['display_phone_number'] ?? null;
        if ($display) {
            MerchantProfileSync::applyWhatsapp($merchant, $settings, $display);
            $merchant->settings = $settings;
            $merchant->save();
        }

        // Submit the owner-notification templates right away so Meta can approve them early.
        try {
            app(ShopTemplateService::class)->sync($merchant->fresh());
        } catch (\Throwable $e) {
            Log::warning('Template submission after signup failed', ['merchant_id' => $merchant->id, 'error' => $e->getMessage()]);
        }

        return $merchant->fresh();
    }

    /**
     * NeoTalab's own WhatsApp number (welcome messages, payment reminders, problem alerts), connected
     * with the same Embedded Signup as a shop, usually keeping the WhatsApp Business app on it.
     * Its token is kept apart from the shared platform token that shops' numbers use.
     *
     * @param  array<string,mixed>  $payload
     */
    public function completePlatform(array $payload): PlatformSetting
    {
        $code = trim((string) ($payload['code'] ?? ''));
        $phoneNumberId = trim((string) ($payload['phoneNumberId'] ?? $payload['phone_number_id'] ?? ''));
        $wabaId = trim((string) ($payload['wabaId'] ?? $payload['waba_id'] ?? ''));
        if ($code === '' || $phoneNumberId === '' || $wabaId === '') {
            throw ValidationException::withMessages(['code' => ['Embedded signup did not return complete data. Try again.']]);
        }
        $coexistence = (bool) ($payload['coexistence'] ?? false);

        $accessToken = $this->exchangeCode($code);
        $this->subscribeWaba($wabaId, $accessToken);
        $pin = null;
        if ($coexistence) {
            $this->startCoexistenceSync($phoneNumberId, $accessToken);
        } else {
            $pin = $this->registerNumber($phoneNumberId, $accessToken);
        }

        $display = Http::withToken($accessToken)->acceptJson()
            ->get($this->whatsapp->graphUrl($phoneNumberId), ['fields' => 'display_phone_number,verified_name'])
            ->json('display_phone_number');

        $platform = PlatformSetting::singleton();
        $settings = $platform->settings ?? [];
        $settings['wa_phone_id'] = $phoneNumberId;
        $settings['wa_phone_access_token'] = Crypt::encryptString($accessToken);
        $settings['wa_waba_id'] = $wabaId;
        $settings['wa_display_phone'] = is_string($display) ? $display : null;
        $settings['wa_coexistence'] = $coexistence;
        if ($pin !== null) {
            $settings['wa_registration_pin'] = Crypt::encryptString($pin);
        }
        $platform->settings = $settings;
        $platform->save();

        app(PlatformTemplateService::class)->submitAll($wabaId, $accessToken);

        return $platform;
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

    /**
     * Registers a newly onboarded number for Cloud API messaging and sets its two-step PIN.
     */
    protected function registerNumber(string $phoneNumberId, string $accessToken): string
    {
        $pin = (string) random_int(100000, 999999);

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post($this->whatsapp->graphUrl("{$phoneNumberId}/register"), [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'phoneNumberId' => ['Meta could not register this number: '.($response->json('error.message') ?? $response->status())],
            ]);
        }

        return $pin;
    }

    protected function startCoexistenceSync(string $phoneNumberId, string $accessToken): void
    {
        foreach (['smb_app_state_sync', 'history'] as $syncType) {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($this->whatsapp->graphUrl("{$phoneNumberId}/smb_app_data"), [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => $syncType,
                ]);

            if (! $response->successful()) {
                // Not fatal for messaging, but the merchant's contacts/history will not be mirrored.
                Log::warning('Coexistence sync request failed', [
                    'phone_number_id' => $phoneNumberId,
                    'sync_type' => $syncType,
                    'body' => $response->json(),
                ]);
            }
        }
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
