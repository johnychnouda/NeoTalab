<?php

namespace App\Models\Concerns;

use App\Models\Merchant;
use App\Models\Scopes\TenantScope;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to any model that stores a `merchant_id` tenant key.
 *
 * - Adds the global TenantScope so reads are automatically constrained to the active tenant.
 * - Auto-fills `merchant_id` on create when a tenant is bound, so callers never have to
 *   (and cannot accidentally cross tenants).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $tenant = app(CurrentTenant::class);
            $column = $model->getTenantColumn();

            if ($tenant->check() && empty($model->{$column})) {
                $model->{$column} = $tenant->id();
            }
        });
    }

    public function getTenantColumn(): string
    {
        return 'merchant_id';
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable().'.'.$this->getTenantColumn();
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Escape hatch for platform-admin / system code that must query across tenants.
     */
    public function newQueryWithoutTenancy()
    {
        return $this->newQueryWithoutScope(TenantScope::class);
    }
}
