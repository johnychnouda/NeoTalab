<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMerchantRequest;
use App\Http\Resources\MerchantResource;
use Illuminate\Http\Request;

/**
 * The authenticated merchant user's own business. There is no "other merchant" to address —
 * the merchant is always derived from the caller, so cross-tenant access is impossible here.
 */
class MerchantController extends Controller
{
    public function show(Request $request): MerchantResource
    {
        return new MerchantResource($request->user()->merchant);
    }

    public function update(UpdateMerchantRequest $request): MerchantResource
    {
        $merchant = $request->user()->merchant;
        $merchant->update($request->validated());

        return new MerchantResource($merchant);
    }
}
