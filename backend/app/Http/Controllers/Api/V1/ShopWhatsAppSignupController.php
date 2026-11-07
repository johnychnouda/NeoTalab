<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectEmbeddedSignupRequest;
use App\Services\MerchantSettingsService;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A shop owner connects the shop's own WhatsApp number from the dashboard (Meta Embedded Signup).
 * The WhatsApp account is then created in the shop's own Meta business, and the owner adds the
 * payment card there. Only a shop with no number yet can do this: changing a connected number stays with NeoTalab.
 */
class ShopWhatsAppSignupController extends Controller
{
    public function config(WhatsAppEmbeddedSignupService $signup): JsonResponse
    {
        return response()->json($signup->config());
    }

    public function connect(
        ConnectEmbeddedSignupRequest $request,
        WhatsAppEmbeddedSignupService $signup,
        MerchantSettingsService $settings,
    ): JsonResponse {
        $merchant = $request->user()->merchant;

        if (MerchantWhatsAppCredentials::resolve($merchant) !== null) {
            throw ValidationException::withMessages([
                'phoneNumberId' => [__('Your WhatsApp number is already connected. To change it, contact NeoTalab.')],
            ]);
        }

        $phoneId = trim((string) $request->validated('phoneNumberId'));
        $usedBy = MerchantWhatsAppCredentials::findMerchantByPhoneId($phoneId);
        if ($usedBy && $usedBy->id !== $merchant->id) {
            Log::warning('WhatsApp signup refused: this number belongs to another shop', ['merchant_id' => $merchant->id]);

            throw ValidationException::withMessages([
                'phoneNumberId' => [__('This WhatsApp number is already used by another shop. Contact NeoTalab.')],
            ]);
        }

        try {
            $merchant = $signup->complete($merchant, $request->validated());
        } catch (ValidationException $e) {
            Log::warning('WhatsApp signup did not finish: '.(collect($e->errors())->flatten()->first() ?: 'unknown reason'), ['merchant_id' => $merchant->id]);

            throw $e;
        }

        return response()->json(['setup' => $settings->setup($merchant)]);
    }
}
