<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class, // roles must exist before assignment
            PlatformSettingsSeeder::class,
            PlatformAdminSeeder::class,
        ]);
    }
}
