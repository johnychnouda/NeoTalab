<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminMerchantResource;
use App\Http\Resources\OnboardingRequestResource;
use App\Services\MerchantWelcomeService;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function index(OnboardingService $onboarding): JsonResponse
    {
        $requests = collect($onboarding->pending())
            ->map(fn ($row) => (new OnboardingRequestResource($row))->resolve())
            ->values()
            ->all();

        return response()->json(['requests' => $requests]);
    }

    public function destroy(string $id, Request $request, OnboardingService $onboarding): JsonResponse
    {
        $onboarding->reject($id, $request->user());

        return response()->json(['message' => 'Request rejected.']);
    }

    public function approve(Request $request, string $id, OnboardingService $onboarding, MerchantWelcomeService $welcome): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:6'],
            'subscriptionStatus' => ['nullable', 'string', 'in:paid,trial,pending'],
            'trialEndsAt' => ['nullable', 'date'],
            'forcePasswordChange' => ['nullable', 'boolean'],
        ]);

        ['merchant' => $merchant, 'user' => $user] = $onboarding->approve($id, $request->user(), $request->all());

        $welcomeSent = false;
        $welcomeError = null;
        try {
            $welcome->send($merchant, $request->input('password'), $user->email);
            $welcomeSent = true;
        } catch (\Throwable $e) {
            $welcomeError = $e->getMessage();
        }

        return response()->json([
            'message' => 'Merchant approved.',
            'merchant' => new AdminMerchantResource($merchant),
            'user' => [
                'email' => $user->email,
            ],
            'welcomeSent' => $welcomeSent,
            'welcomeError' => $welcomeError,
        ]);
    }
}
