<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\OrderConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Whish calls this (signed URL) after a payment succeeds or fails. The callback only triggers a
 * check: the result always comes from Whish's collect-status API. The signature covers the path
 * (order + result); query parameters Whish may append are ignored instead of breaking it.
 */
class WhishCallbackController extends Controller
{
    public function __invoke(Request $request, string $order, OrderConfirmation $confirmation): JsonResponse
    {
        if (! URL::hasValidRelativeSignature($request, fn (string $parameter) => true)) {
            return response()->json(['ok' => false], 403);
        }

        $model = (new Order)->newQueryWithoutTenancy()->find($order);
        $merchant = $model ? Merchant::find($model->merchant_id) : null;

        if (! $model || ! $merchant) {
            return response()->json(['ok' => false], 404);
        }

        return response()->json(['ok' => true, 'payment_status' => $confirmation->settleWhish($merchant, $model)]);
    }
}
