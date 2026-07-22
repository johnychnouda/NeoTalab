<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function dashboard(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $days = (int) $request->query('days', 30);

        return response()->json($analytics->dashboard($request->user()->merchant, $days));
    }
}
