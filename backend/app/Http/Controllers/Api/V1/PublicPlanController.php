<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

/** The plan shown on the public website and /join page, as set by the platform owner. */
class PublicPlanController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $plan = PlatformSetting::singleton();

        return response()->json([
            'trial_days' => max(1, (int) ($plan->trial_days ?: 3)),
            'currency' => $plan->currency ?: 'USD',
            'monthly_price' => (float) $plan->subscription_price,
            'yearly_price' => (float) $plan->subscription_yearly_price,
        ]);
    }
}
