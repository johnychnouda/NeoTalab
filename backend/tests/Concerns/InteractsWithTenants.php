<?php

namespace Tests\Concerns;

use App\Models\Merchant;
use App\Models\User;
use App\Support\Roles;

trait InteractsWithTenants
{
    /**
     * Create a merchant plus one user with the given role.
     *
     * @return array{0:Merchant,1:User}
     */
    protected function merchantWithUser(string $role = Roles::MERCHANT_OWNER, array $merchantAttrs = []): array
    {
        $merchant = Merchant::factory()->create($merchantAttrs);
        $user = User::factory()->forMerchant($merchant)->create();
        $user->assignRole($role);

        return [$merchant, $user];
    }

    protected function platformAdmin(): User
    {
        $admin = User::factory()->platformAdmin()->create();
        $admin->assignRole(Roles::PLATFORM_SUPER_ADMIN);

        return $admin;
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
