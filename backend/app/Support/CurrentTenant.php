<?php

namespace App\Support;

use App\Models\Merchant;

/**
 * Request-scoped holder for the active tenant (merchant).
 *
 * Bound as a singleton (see AppServiceProvider). ResolveTenant middleware sets it from the
 * authenticated user. When no tenant is set — before authentication, or for platform
 * super-admins — the TenantScope applies no constraint, so those requests see everything.
 */
class CurrentTenant
{
    protected ?Merchant $merchant = null;

    public function set(?Merchant $merchant): void
    {
        $this->merchant = $merchant;
    }

    public function get(): ?Merchant
    {
        return $this->merchant;
    }

    public function id(): ?string
    {
        return $this->merchant?->getKey();
    }

    public function check(): bool
    {
        return $this->merchant !== null;
    }

    public function forget(): void
    {
        $this->merchant = null;
    }
}
