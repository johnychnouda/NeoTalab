<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

/** What the public /join page needs to know about the plan (no prices: those are agreed on approval). */
class PublicPlanController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $days = (int) (PlatformSetting::singleton()->trial_days ?: 3);

        return response()->json(['trial_days' => max(1, $days)]);
    }
}
