<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BroadcastRequest;
use App\Http\Requests\Admin\OwnerStoreMerchantRequest;
use App\Http\Requests\Admin\StoreMerchantRequest;
use App\Http\Requests\Admin\UpdateMerchantBotRequest;
use App\Http\Requests\Admin\SendMerchantWelcomeRequest;
use App\Http\Requests\Admin\UpdateMerchantRequest;
use App\Http\Resources\AdminMerchantResource;
use App\Models\Merchant;
use App\Services\AdminMerchantService;
use App\Services\MerchantWelcomeService;
use App\Services\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform-super-admin only. Merchant is not tenant-scoped, and the caller has no bound tenant,
 * so these endpoints intentionally see and act across all merchants.
 */
class MerchantController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminMerchantResource::collection(Merchant::query()->latest()->get());
    }

    public function store(StoreMerchantRequest $request, TenantProvisioningService $provisioning): JsonResponse
    {
        $payload = $request->provisioning();
        ['merchant' => $merchant] = $provisioning->provision($payload['merchant'], $payload['owner']);

        return (new AdminMerchantResource($merchant))->response()->setStatusCode(201);
    }

    public function storeFromOwnerPortal(OwnerStoreMerchantRequest $request, AdminMerchantService $adminMerchants): JsonResponse
    {
        ['merchant' => $merchant] = $adminMerchants->provisionFromOwnerPortal($request->validated());

        return (new AdminMerchantResource($merchant))->response()->setStatusCode(201);
    }

    public function show(string $id, AdminMerchantService $adminMerchants): AdminMerchantResource
    {
        return new AdminMerchantResource($adminMerchants->find($id));
    }

    public function update(UpdateMerchantRequest $request, string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        $merchant = $adminMerchants->update($adminMerchants->find($id), $request->validated());

        return response()->json([
            'data' => new AdminMerchantResource($merchant),
            'merchant' => new AdminMerchantResource($merchant),
            'message' => 'Merchant updated.',
        ]);
    }

    public function destroy(string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        $merchant = $adminMerchants->find($id);
        $name = $merchant->name;
        $adminMerchants->delete($merchant);

        return response()->json([
            'message' => 'Merchant removed.',
            'merchant' => ['id' => $id, 'shop_name' => $name],
        ]);
    }

    public function impersonate(string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        $result = $adminMerchants->impersonate($adminMerchants->find($id));

        return response()->json([
            'token' => $result['token'],
            'message' => "Impersonating {$result['merchant']->name}",
            'merchant' => new AdminMerchantResource($result['merchant']),
        ]);
    }

    public function payments(string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        return response()->json([
            'payments' => $adminMerchants->payments($adminMerchants->find($id)),
        ]);
    }

    public function updateBot(UpdateMerchantBotRequest $request, string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        $merchant = $adminMerchants->updateBot($adminMerchants->find($id), $request->validated());

        return response()->json([
            'data' => new AdminMerchantResource($merchant),
            'merchant' => new AdminMerchantResource($merchant),
            'message' => 'Bot credentials saved.',
        ]);
    }

    public function testBot(string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        return response()->json($adminMerchants->testBot($adminMerchants->find($id)));
    }

    public function restartBot(string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        $merchant = $adminMerchants->restartBot($adminMerchants->find($id));

        return response()->json([
            'success' => true,
            'merchant' => new AdminMerchantResource($merchant),
        ]);
    }

    public function registerWebhook(string $id, AdminMerchantService $adminMerchants): JsonResponse
    {
        $info = $adminMerchants->registerWebhook($adminMerchants->find($id));

        return response()->json(array_merge(['success' => true], $info));
    }

    public function sendWelcome(
        SendMerchantWelcomeRequest $request,
        string $id,
        AdminMerchantService $adminMerchants,
        MerchantWelcomeService $welcome,
    ): JsonResponse {
        $merchant = $adminMerchants->find($id);

        if ($request->boolean('regenerateAccess')) {
            $otp = (string) $request->input('otp', '');
            if (strlen($otp) !== 6) {
                $otp = (string) random_int(100000, 999999);
            }

            return response()->json($welcome->resendAccess($merchant, $otp));
        }

        $result = $welcome->send(
            $merchant,
            $request->input('otp'),
            $request->input('loginEmail'),
        );

        return response()->json($result);
    }

    public function broadcast(BroadcastRequest $request, AdminMerchantService $adminMerchants): JsonResponse
    {
        $result = $adminMerchants->broadcast((string) $request->input('message'));

        return response()->json($result);
    }
}
