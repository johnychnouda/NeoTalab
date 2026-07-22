<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\SetInitialPasswordRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, TenantProvisioningService $provisioning): JsonResponse
    {
        $payload = $request->provisioning();
        ['merchant' => $merchant, 'user' => $user] = $provisioning->provision($payload['merchant'], $payload['owner']);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'merchant' => new MerchantResource($merchant),
        ], 201);
    }

    public function login(LoginRequest $request, AuthService $auth): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $auth->login(
            (string) $request->input('email'),
            (string) $request->input('password'),
        );

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'merchant' => $user->merchant ? new MerchantResource($user->merchant) : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'merchant' => $user->merchant ? new MerchantResource($user->merchant) : null,
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request, AuthService $auth): JsonResponse
    {
        $result = $auth->changePassword(
            $request->user(),
            (string) $request->input('current_password'),
            (string) $request->input('password'),
        );

        return response()->json($result);
    }

    public function revokeOtherSessions(Request $request, AuthService $auth): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();

        return response()->json($auth->revokeOtherSessions($request->user(), $token));
    }

    public function setInitialPassword(SetInitialPasswordRequest $request, AuthService $auth): JsonResponse
    {
        return response()->json(
            $auth->setInitialPassword($request->user(), (string) $request->input('password'))
        );
    }
}
