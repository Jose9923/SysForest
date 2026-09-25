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
        // La evidencia textual del GPS se conserva; el parser normaliza una copia para interpretar.
        $middleware->trimStrings(except: ['coordinate_raw', 'flowering_development', 'fruiting_development', 'fauna_observations', 'general_observations']);
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
        $exceptions->render(fn (Throwable $exception, Request $request) => app(\App\Http\PublicExceptionRenderer::class)->render($exception, $request));
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (!$request->is('evidences/*', 'trees/*/evidences*', 'inspections/*/evidences*', 'maintenances/*/evidences*')
                || $exception instanceof \Illuminate\Validation\ValidationException
                || $exception instanceof AuthenticationException) {
                return null;
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $message = match ($status) {
                403 => 'No tienes permiso para realizar esta operación con evidencias.',
                404 => 'La evidencia o su propietario no está disponible.',
                409 => 'La evidencia fue modificada por otro usuario.',
                413 => 'El lote supera el tamaño máximo permitido.',
                419 => 'La sesión del formulario caducó. Actualiza la página antes de continuar.',
                429 => 'Has realizado demasiadas solicitudes. Espera antes de volver a intentarlo.',
                default => 'No fue posible completar la operación. Consulta la galería antes de volver a intentarlo.',
            };

            return $request->expectsJson() ? response()->json(['message' => $message], $status)
                : response()->view('errors.evidence', ['message' => $message, 'status' => $status], $status);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (!$request->is('maintenances', 'maintenances/*', 'trees/*/maintenances*')
                || $exception instanceof \Illuminate\Validation\ValidationException
                || $exception instanceof AuthenticationException) {
                return null;
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $message = match ($status) {
                403 => 'No tienes permiso para realizar esta operación con mantenimientos.',
                404 => 'El mantenimiento o su árbol no está disponible.',
                409 => 'El mantenimiento fue modificado por otro usuario.',
                419 => 'La sesión del formulario caducó. Actualiza la página antes de continuar.',
                429 => 'Has realizado demasiadas solicitudes. Espera antes de volver a intentarlo.',
                default => 'No fue posible completar la operación. Consulta el mantenimiento antes de volver a intentarlo.',
            };
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], $status);
            }
            if ($exception instanceof \App\Exceptions\StaleMaintenanceException) {
                return response()->view('errors.409', ['exception' => $exception], $status);
            }

            return response()->view('maintenances.error', ['message' => $message, 'status' => $status], $status);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (!$request->is('map', 'map/*', 'api/v1/map/*')
                || $exception instanceof \Illuminate\Validation\ValidationException
                || $exception instanceof AuthenticationException) {
                return null;
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $message = match ($status) {
                403 => 'No tienes permiso para consultar el mapa en este alcance.',
                404 => 'El registro o territorio solicitado no está disponible.',
                405 => 'Esta acción requiere el formulario de la ficha.',
                419 => 'La sesión caducó. Actualiza la página antes de continuar.',
                429 => 'Has realizado demasiadas consultas. Espera unos segundos antes de continuar.',
                default => 'No fue posible consultar el mapa. Inténtalo de nuevo en unos momentos.',
            };
            $response = $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => $message], $status)
                : response()->view('maps.error', ['message' => $message, 'status' => $status], $status);

            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $response->headers->add($exception->getHeaders());
            }

            return $response->header('Cache-Control', 'private, no-store');
        });
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token', 'refresh_token', 'access_token']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/v1/map/*') || $request->expectsJson()) {
                return response()->json(['message' => 'La sesión no es válida. Inicia sesión nuevamente.'], 401);
            }

            return redirect()->route($request->cookie(config('security.refresh_cookie')) ? 'auth.renew' : 'login');
        });
    })->create();
