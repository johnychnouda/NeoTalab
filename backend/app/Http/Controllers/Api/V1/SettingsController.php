<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MerchantSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function profile(Request $request, MerchantSettingsService $settings): JsonResponse
    {
        return response()->json([
            'profile' => $settings->profile($request->user()->merchant),
        ]);
    }

    public function updateProfile(Request $request, MerchantSettingsService $settings): JsonResponse
    {
        $merchant = $settings->updateProfile($request->user()->merchant, $request->all());

        return response()->json([
            'profile' => $settings->profile($merchant),
            'message' => 'Profile updated.',
        ]);
    }

    public function updateMode(Request $request, MerchantSettingsService $settings): JsonResponse
    {
        $request->validate(['mode' => ['required', 'string', 'in:auto,busy,manual,closed']]);
        $merchant = $settings->updateMode($request->user()->merchant, $request->input('mode'));

        return response()->json([
            'mode' => ($merchant->settings ?? [])['mode'] ?? 'auto',
            'message' => 'Mode updated.',
        ]);
    }

    public function hours(Request $request, MerchantSettingsService $settings): JsonResponse
    {
        $hours = $settings->hours($request->user()->merchant);

        return response()->json([
            'hours' => collect($hours)->map(fn ($h) => [
                'day_of_week' => $h->day_of_week,
                'opens_at' => $h->opens_at,
                'closes_at' => $h->closes_at,
                'is_closed' => $h->is_closed,
            ]),
        ]);
    }

    public function replaceHours(Request $request, MerchantSettingsService $settings): JsonResponse
    {
        $request->validate(['hours' => ['required', 'array', 'size:7']]);
        $settings->replaceHours($request->user()->merchant, $request->input('hours'));

        return response()->json(['message' => 'Hours updated.']);
    }

    public function zones(Request $request, MerchantSettingsService $settings): JsonResponse
    {
        return response()->json(['zones' => $settings->zones($request->user()->merchant)]);
    }

    public function storeZone(Request $request, MerchantSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'deliveryFee' => ['nullable', 'numeric', 'min:0'],
            'minimumOrder' => ['nullable', 'numeric', 'min:0'],
        ]);

        $zone = $settings->createZone($request->user()->merchant, $data);

        return response()->json(['zone' => $zone], 201);
    }

    public function destroyZone(Request $request, MerchantSettingsService $settings, string $zone): JsonResponse
    {
        $settings->deleteZone($request->user()->merchant, $zone);

        return response()->json(['message' => 'Zone removed.']);
    }
}
