<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate checked against the User model directly (guard-agnostic), avoiding the
 * web-vs-sanctum guard mismatch that spatie's own role middleware is prone to under Sanctum.
 *
 * Usage: ->middleware('role:merchant-owner,merchant-admin')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user && $user->hasAnyRole($roles), 403, 'Insufficient role.');

        return $next($request);
    }
}
