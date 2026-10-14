<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform-super-admin only: monthly AI and WhatsApp usage per shop. */
class UsageController extends Controller
{
    public function index(Request $request, UsageService $usage): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->format('Y-m');
        }

        return response()->json($usage->report($month));
    }
}
