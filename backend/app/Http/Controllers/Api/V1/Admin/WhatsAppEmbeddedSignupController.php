<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectEmbeddedSignupRequest;
use App\Http\Resources\AdminMerchantResource;
use App\Http\Resources\PlatformSettingsResource;
use App\Services\AdminMerchantService;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
        $merchant = $adminMerchants->find($id);

        try {
            $merchant = $signup->complete($merchant, $request->validated());
        } catch (ValidationException $e) {
            // The owner sees the reason for a few seconds on screen; this keeps it in Inbox → System.
            Log::warning('WhatsApp signup did not finish: '.$this->reason($e), ['merchant_id' => $merchant->id]);

            throw $e;
        }

        return response()->json([
            'data' => new AdminMerchantResource($merchant),
            'merchant' => new AdminMerchantResource($merchant),
            'message' => 'WhatsApp connected via Meta Embedded Signup.',
        ]);
    }

    /** NeoTalab's own WhatsApp number (owner portal → Settings → WhatsApp). */
    public function connectPlatform(ConnectEmbeddedSignupRequest $request, WhatsAppEmbeddedSignupService $signup): JsonResponse
    {
        try {
            $setting = $signup->completePlatform($request->validated());
        } catch (ValidationException $e) {
            Log::warning("WhatsApp signup did not finish for NeoTalab's own number: ".$this->reason($e));

            throw $e;
        }

        return response()->json([
            'settings' => new PlatformSettingsResource($setting, $request->user()->password_changed_at?->toIso8601String()),
            'message' => 'NeoTalab WhatsApp connected via Meta Embedded Signup.',
        ]);
    }

    private function reason(ValidationException $e): string
    {
        return collect($e->errors())->flatten()->first() ?: 'unknown reason';
    }
}
