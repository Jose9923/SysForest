<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt.cookie' => \App\Http\Middleware\AuthenticateJwtCookie::class,
            'jwt.optional' => \App\Http\Middleware\OptionalJwtCookie::class,
            'identity.session' => \App\Http\Middleware\BindAuthenticatedSession::class,
            'identity.verified' => \App\Http\Middleware\RequireVerifiedEmail::class,
            'identity.headers' => \App\Http\Middleware\IdentityResponseHeaders::class,
            'tenant.share' => \App\Http\Middleware\ShareTenantContext::class,
            'tenant.context' => \App\Http\Middleware\ResolveTenantContext::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token', 'refresh_token', 'access_token']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'La sesión no es válida. Inicia sesión nuevamente.'], 401);
            }

            return redirect()->route($request->cookie(config('security.refresh_cookie')) ? 'auth.renew' : 'login');
        });
    })->create();
