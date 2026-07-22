<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function live(Request $request, OrderService $orders): JsonResponse
    {
        return response()->json(['orders' => $orders->live($request->user()->merchant)]);
    }

    public function index(Request $request, OrderService $orders): JsonResponse
    {
        return response()->json([
            'orders' => $orders->list(
                $request->user()->merchant,
                $request->query('status'),
                $request->query('date'),
                (int) $request->query('limit', 50),
            ),
        ]);
    }

    public function action(Request $request, OrderService $orders, string $order, string $action): JsonResponse
    {
        $mapped = $orders->transition($request->user()->merchant, $order, $action, $request->all());

        return response()->json(['order' => $mapped, 'message' => 'Order updated.']);
    }
}
