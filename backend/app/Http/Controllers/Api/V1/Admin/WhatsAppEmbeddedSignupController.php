<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectEmbeddedSignupRequest;
use App\Http\Resources\AdminMerchantResource;
use App\Http\Resources\PlatformSettingsResource;
use App\Services\AdminMerchantService;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use Illuminate\Http\JsonResponse;

class WhatsAppEmbeddedSignupController extends Controller
{
    public function config(WhatsAppEmbeddedSignupService $signup): JsonResponse
    {
        return response()->json($signup->config());
    }

    public function connect(
        ConnectEmbeddedSignupRequest $request,
        string $id,
        AdminMerchantService $adminMerchants,
        WhatsAppEmbeddedSignupService $signup,
    ): JsonResponse {
        $merchant = $signup->complete(
            $adminMerchants->find($id),
            $request->validated(),
        );

        return response()->json([
            'data' => new AdminMerchantResource($merchant),
            'merchant' => new AdminMerchantResource($merchant),
            'message' => 'WhatsApp connected via Meta Embedded Signup.',
        ]);
    }

    /** NeoTalab's own WhatsApp number (owner portal → Settings → WhatsApp). */
    public function connectPlatform(ConnectEmbeddedSignupRequest $request, WhatsAppEmbeddedSignupService $signup): JsonResponse
    {
        $setting = $signup->completePlatform($request->validated());

        return response()->json([
            'settings' => new PlatformSettingsResource($setting, $request->user()->password_changed_at?->toIso8601String()),
            'message' => 'NeoTalab WhatsApp connected via Meta Embedded Signup.',
        ]);
    }
}
