<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemAlert;
use App\Services\LaunchChecklistService;
use App\Services\SystemAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner portal → Inbox → System: platform problems to look at. */
class SystemAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $resolved = $request->boolean('resolved');

        $alerts = SystemAlert::query()
            ->with('merchant:id,name')
            ->when($resolved, fn ($q) => $q->whereNotNull('resolved_at'), fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->map(fn (SystemAlert $a) => [
                'id' => $a->id,
                'level' => $a->level,
                'message' => $a->message,
                'context' => $a->context ?? [],
                'merchant_id' => $a->merchant_id,
                'shop_name' => $a->merchant?->name,
                'occurrences' => $a->occurrences,
                'first_seen_at' => $a->first_seen_at?->toIso8601String(),
                'last_seen_at' => $a->last_seen_at?->toIso8601String(),
                'notified_at' => $a->notified_at?->toIso8601String(),
                'resolved_at' => $a->resolved_at?->toIso8601String(),
            ]);

        return response()->json(['alerts' => $alerts]);
    }

    public function resolve(string $id): JsonResponse
    {
        SystemAlert::query()->whereKey($id)->firstOrFail()->forceFill(['resolved_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function resolveAll(): JsonResponse
    {
        $count = SystemAlert::query()->whereNull('resolved_at')->update(['resolved_at' => now()]);

        return response()->json(['ok' => true, 'resolved' => $count]);
    }

    /** Launch checklist: which server settings are in place. */
    public function status(LaunchChecklistService $checklist): JsonResponse
    {
        return response()->json(['checks' => $checklist->checks()]);
    }

    /** Sends a test alert so the owner can check email / WhatsApp delivery. */
    public function test(SystemAlertService $alerts): JsonResponse
    {
        return response()->json(['channels' => $alerts->sendTest()]);
    }
}
