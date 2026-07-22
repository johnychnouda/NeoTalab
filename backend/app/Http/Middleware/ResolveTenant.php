<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active tenant from the authenticated user. Must run AFTER auth:sanctum.
 * Users with a merchant_id get their merchant bound (scoping all subsequent tenant queries);
 * platform super-admins (merchant_id === null) leave the tenant unbound and see across tenants.
 */
class ResolveTenant
{
    public function __construct(protected CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->merchant_id) {
            $this->tenant->set($user->merchant);
        }

        return $next($request);
    }
}
