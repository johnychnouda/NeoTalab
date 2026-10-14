<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * The owner's history of actions (approvals, payments, suspensions…), kept on the server so it
 * is the same on every device. Platform super admin only.
 */
class ActivityController extends Controller
{
    public const TYPES = ['merchant', 'billing', 'bot', 'system'];

    public function index(): JsonResponse
    {
        $items = Activity::query()->whereIn('log_name', self::TYPES)->latest('id')->limit(300)->get()
            ->map(fn (Activity $a) => ['id' => $a->id, 'type' => $a->log_name, 'message' => $a->description, 'at' => $a->created_at?->toIso8601String()]);

        return response()->json(['activity' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'message' => ['required', 'string', 'max:500'],
        ]);

        activity($data['type'])->causedBy($request->user())->log($data['message']);

        return response()->json(['ok' => true], 201);
    }
}
