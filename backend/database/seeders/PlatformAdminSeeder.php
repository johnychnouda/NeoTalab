<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('OWNER_EMAIL') ?: 'owner@example.com';
        $password = env('OWNER_PASSWORD');
        $generated = blank($password);

        if ($generated) {
            $password = Str::password(20);
        }

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'merchant_id' => null,
                'name' => env('OWNER_NAME') ?: 'Platform owner',
                'status' => 'active',
                'password' => $password, // hashed by the model cast
                'email_verified_at' => now(),
            ],
        );

        if ($admin->wasRecentlyCreated && $generated) {
            $this->command?->warn("OWNER_PASSWORD not set — generated owner password for {$email}: {$password}");
        }

        $admin->assignRole(Roles::PLATFORM_SUPER_ADMIN);
    }
}
