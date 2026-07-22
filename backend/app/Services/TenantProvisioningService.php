<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new tenant (merchant) together with its owner user, atomically.
 * Used by both self-service registration and platform-admin provisioning.
 */
class TenantProvisioningService
{
    /**
     * @param  array{business_type?:string,name:string,locale?:string,currency?:string,timezone?:string,whatsapp_number?:string,phone?:string}  $merchantData
     * @param  array{name:string,email:string,password:string,phone?:string}  $ownerData
     * @return array{merchant:Merchant,user:User}
     */
    public function provision(array $merchantData, array $ownerData): array
    {
        return DB::transaction(function () use ($merchantData, $ownerData) {
            $merchant = Merchant::create([
                'business_type' => $merchantData['business_type'] ?? 'general',
                'name' => $merchantData['name'],
                'slug' => $this->uniqueSlug($merchantData['name']),
                'status' => 'active',
                'locale' => $merchantData['locale'] ?? 'en',
                'currency' => $merchantData['currency'] ?? 'USD',
                'timezone' => $merchantData['timezone'] ?? 'Asia/Beirut',
                'whatsapp_number' => $merchantData['whatsapp_number'] ?? null,
                'phone' => $merchantData['phone'] ?? null,
                'plan' => 'basic',
                'trial_ends_at' => now()->addDays((int) (config('neotalab.trial_days', 7))),
            ]);

            // Registration runs with no bound tenant, so set merchant_id explicitly.
            $user = new User([
                'name' => $ownerData['name'],
                'email' => $ownerData['email'],
                'phone' => $ownerData['phone'] ?? null,
                'status' => 'active',
                'password' => $ownerData['password'], // hashed by the model cast
            ]);
            $user->merchant_id = $merchant->id;
            $user->save();

            $user->assignRole(Roles::MERCHANT_OWNER);

            return ['merchant' => $merchant, 'user' => $user];
        });
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'merchant';
        $slug = $base;
        $i = 1;

        while (Merchant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
