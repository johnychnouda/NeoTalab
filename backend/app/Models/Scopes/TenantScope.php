<?php

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a BelongsToTenant model to the active tenant.
 * No-op when no tenant is bound (pre-auth requests and platform super-admins),
 * which is what lets login work and lets platform admins see across tenants.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(CurrentTenant::class);

        if ($tenant->check()) {
            $builder->where($model->getQualifiedTenantColumn(), $tenant->id());
        }
    }
}
