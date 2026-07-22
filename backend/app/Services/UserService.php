<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages users within the active tenant. All reads/writes go through the tenant-scoped
 * User model, so a merchant manager can never see or touch another merchant's users.
 */
class UserService
{
    /** @return Collection<int,User> */
    public function listForCurrentTenant(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    /**
     * @param  array{name:string,email:string,password:string,phone?:string,role:string}  $data
     */
    public function create(array $data): User
    {
        // merchant_id is auto-filled from the bound tenant by the BelongsToTenant trait.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'status' => 'active',
            'password' => $data['password'],
        ]);

        $user->assignRole($data['role']);

        return $user;
    }

    /**
     * @param  array{name?:string,phone?:string,status?:string,role?:string,password?:string}  $data
     */
    public function update(User $user, array $data): User
    {
        $user->fill(array_filter([
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        if (! empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return $user;
    }
}
