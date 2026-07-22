<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Users within the active tenant. The User model is tenant-scoped, so implicit route-model
 * binding on {user} resolves only within the caller's merchant — a cross-tenant id yields 404.
 */
class UserController extends Controller
{
    public function index(UserService $users): AnonymousResourceCollection
    {
        return UserResource::collection($users->listForCurrentTenant());
    }

    public function store(StoreUserRequest $request, UserService $users): JsonResponse
    {
        $user = $users->create($request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, string $user, UserService $users): UserResource
    {
        // Explicit, tenant-scoped lookup (tenant is already bound by the `tenant` middleware).
        // A cross-tenant id yields 404 via the global TenantScope.
        $model = User::query()->findOrFail($user);

        return new UserResource($users->update($model, $request->validated()));
    }

    public function destroy(Request $request, string $user): JsonResponse
    {
        $model = User::query()->findOrFail($user);

        abort_if($request->user()->is($model), 422, 'You cannot delete your own account.');

        $model->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
