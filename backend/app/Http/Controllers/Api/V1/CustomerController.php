<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request, CustomerService $customers): JsonResponse
    {
        $blocked = $request->query('blocked');
        $blockedFilter = $blocked === null || $blocked === '' ? null : filter_var($blocked, FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'customers' => $customers->list(
                $request->user()->merchant,
                $request->query('search'),
                $blockedFilter,
                (int) $request->query('limit', 50),
            ),
        ]);
    }

    public function block(Request $request, CustomerService $customers, string $customer): JsonResponse
    {
        $row = $customers->block($request->user()->merchant, $customer, $request->input('reason'));

        return response()->json(['customer' => $row, 'message' => 'Customer blocked.']);
    }

    public function unblock(Request $request, CustomerService $customers, string $customer): JsonResponse
    {
        $row = $customers->unblock($request->user()->merchant, $customer);

        return response()->json(['customer' => $row, 'message' => 'Customer unblocked.']);
    }
}
