<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitOnboardingRequest;
use App\Http\Resources\OnboardingRequestResource;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;

class PublicOnboardingController extends Controller
{
    public function store(SubmitOnboardingRequest $request, OnboardingService $onboarding): JsonResponse
    {
        $row = $onboarding->submit($request->validated());

        return response()->json([
            'message' => 'Application received. We will contact you soon.',
            'request' => new OnboardingRequestResource($row),
        ], 201);
    }
}
