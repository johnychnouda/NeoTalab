<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active tenant from the authenticated user. Must run AFTER auth:sanctum.
 * Users with a merchant_id get their merchant bound (scoping all subsequent tenant queries);
 * platform super-admins (merchant_id === null) leave the tenant unbound and see across tenants.
 *
 * An unbound tenant means "see everything", so only a platform super-admin may run without one:
 * any other login whose shop is missing is refused, and so is a disabled login's open session.
 */
class ResolveTenant
{
    public function __construct(protected CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        abort_if($user->status === 'disabled', 401, 'This account is disabled.');

        if ($user->merchant_id) {
            $merchant = $user->merchant;
            abort_unless($merchant, 403, 'This account has no shop.');
            $this->tenant->set($merchant);
        } else {
            abort_unless($user->hasRole(Roles::PLATFORM_SUPER_ADMIN), 403, 'This account has no shop.');
        }

        return $next($request);
    }
}
