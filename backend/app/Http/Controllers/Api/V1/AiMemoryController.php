<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\AiMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiMemoryController extends Controller
{
    public function index(Request $request, AiMemoryService $memories): JsonResponse
    {
        $scope = $request->query('scope');
        $rows = $memories->list(is_string($scope) ? $scope : null, max(1, min(200, (int) $request->query('limit', 100))));

        return response()->json([
            'memories' => collect($rows)->map(fn ($m) => [
                'id' => $m->id,
                'scope' => $m->scope,
                'source' => $m->source,
                'content' => $m->content,
                'hits' => $m->hits,
                'customer_name' => $m->customer?->name,
                'customer_phone' => $m->customer?->phone,
                'updated_at' => $m->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function destroy(Request $request, AiMemoryService $memories, string $memory): JsonResponse
    {
        if (! $memories->forget($memory)) {
            return response()->json(['message' => 'Memory not found.'], 404);
        }

        return response()->json(['message' => 'Memory deleted.']);
    }

    public function clear(Request $request, AiMemoryService $memories): JsonResponse
    {
        $scope = $request->input('scope');
        $n = $memories->forgetAll(is_string($scope) ? $scope : null);

        return response()->json(['message' => 'Memories cleared.', 'deleted' => $n]);
    }
}
