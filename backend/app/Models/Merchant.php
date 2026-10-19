<?php

namespace App\Models;

use App\Support\Roles;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant. A single business of any type. NOT tenant-scoped itself (it is the tenant root).
 */
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'business_type',
        'name',
        'name_ar',
        'name_fr',
        'slug',
        'description',
        'logo_url',
        'email',
        'phone',
        'whatsapp_number',
        'address',
        'status',
        'locale',
        'currency',
        'timezone',
        'plan',
        'billing_cycle',
        'trial_ends_at',
        'subscription_starts_at',
        'subscription_ends_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'subscription_starts_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The account that signs in to this shop: its owner, else the first user. */
    public function ownerUser(): ?User
    {
        $users = $this->users()->orderBy('created_at')->get();

        return $users->first(fn (User $u) => $u->hasRole(Roles::MERCHANT_OWNER)) ?? $users->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
