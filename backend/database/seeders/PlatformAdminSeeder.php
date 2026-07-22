<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('OWNER_EMAIL', 'johnychnouda@gmail.com')],
            [
                'merchant_id' => null,
                'name' => 'Johny',
                'status' => 'active',
                'password' => env('OWNER_PASSWORD', 'neotalab2025'), // hashed by the model cast
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(Roles::PLATFORM_SUPER_ADMIN);
    }
}
