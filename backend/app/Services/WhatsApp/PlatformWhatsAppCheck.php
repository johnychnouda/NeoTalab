<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformSetting;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Support\Facades\Http;

/**
 * Asks Meta whether NeoTalab's own number is really connected, with the saved ID and token.
 * The owner portal's badge shows this answer instead of only "an ID and a token are filled in".
 * Never returns the token.
 */
class PlatformWhatsAppCheck
{
    public function __construct(protected WhatsAppService $whatsapp) {}

    /** @return array{state: 'not_set'|'connected'|'problem', phone?: ?string, name?: ?string, name_status?: ?string, message?: string} */
    public function run(): array
    {
        $phoneId = trim((string) ((PlatformSetting::singleton()->settings ?? [])['wa_phone_id'] ?? ''));
        $token = MerchantWhatsAppCredentials::platformSenderToken();
        if ($phoneId === '' || $token === '') {
            return ['state' => 'not_set'];
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->get($this->whatsapp->graphUrl($phoneId), [
                'fields' => 'display_phone_number,verified_name,status,name_status',
            ]);
        } catch (\Throwable) {
            return ['state' => 'problem', 'message' => 'Could not reach Meta. Try again in a minute.'];
        }

        if (! $response->successful()) {
            return ['state' => 'problem', 'message' => (string) ($response->json('error.message') ?: 'Meta refused the request.')];
        }

        $status = (string) $response->json('status');
        $result = [
            'phone' => $response->json('display_phone_number'),
            'name' => $response->json('verified_name'),
            'name_status' => $response->json('name_status'),
        ];

        return $status === 'CONNECTED'
            ? ['state' => 'connected'] + $result
            : ['state' => 'problem', 'message' => 'Meta says this number is '.($status !== '' ? strtolower($status) : 'not connected').'.'] + $result;
    }
}
