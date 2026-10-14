<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway's proxy sits in front of the app: trust its X-Forwarded-* headers so the real
        // visitor IP is used (login and /join limits are per visitor) and URLs stay https.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'role' => EnsureRole::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                throw new AuthenticationException('Unauthenticated.');
            }
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error alerts: unhandled exceptions go to Sentry when SENTRY_LARAVEL_DSN is set.
        Integration::handles($exceptions);

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
