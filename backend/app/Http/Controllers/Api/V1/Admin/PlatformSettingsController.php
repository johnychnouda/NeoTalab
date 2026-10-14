<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Http\Resources\PlatformSettingsResource;
use App\Services\PlatformSettingsService;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingsController extends Controller
{
    public function __construct(protected PlatformSettingsService $settings) {}

    public function show(Request $request): PlatformSettingsResource
    {
        return new PlatformSettingsResource(
            $this->settings->get(),
            passwordChangedAt: $request->user()->password_changed_at?->toIso8601String(),
        );
    }

    public function update(UpdatePlatformSettingsRequest $request): PlatformSettingsResource
    {
        $setting = $this->settings->update($request->validated());

        return new PlatformSettingsResource(
            $setting,
            passwordChangedAt: $request->user()->password_changed_at?->toIso8601String(),
        );
    }

    /** Owner-only: reveal the platform WhatsApp access token shown in Settings. */
    public function revealWhatsAppAccessToken(): JsonResponse
    {
        $platform = $this->settings->get()->settings ?? [];
        $fromSettings = trim((string) ($platform['wa_access_token'] ?? ''));
        $token = $fromSettings !== ''
            ? $fromSettings
            : MerchantWhatsAppCredentials::platformAccessToken();

        if ($token === '') {
            return response()->json([
                'message' => 'No WhatsApp access token is configured.',
                'wa_access_token' => null,
            ], 404);
        }

        return response()->json([
            'wa_access_token' => $token,
        ]);
    }
}
