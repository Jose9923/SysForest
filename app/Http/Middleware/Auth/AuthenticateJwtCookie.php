<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Auth\AuthCookies;
use App\Services\Auth\SessionService;
use App\Services\Auth\TokenService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwtCookie
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly SessionService $sessions,
        private readonly AuthCookies $cookies,
    ) {
    }

    public function handle(Request $request, Closure $next, bool $optional = false): Response
    {
        $guard = Auth::guard('web');
        $previousResolver = $request->getUserResolver();
        $guard->forgetUser();
        $request->setUserResolver(static fn () => null);
        $request->attributes->remove('auth_session');

        try {
            $session = null;
            $clearCookies = false;
            $access = $request->cookie((string) config('security.access_cookie'));

            try {
                // Solo esta fase puede convertirse en acceso anónimo a una página pública.
                if (! is_string($access) || $access === '') {
                    throw new AuthenticationException('No fue posible autenticar la solicitud.');
                }

                $claims = $this->tokens->decode($access);
                $session = $this->sessions->authenticate($claims['sid'], (int) $claims['sub']);
                if ($session->client_type !== 'web') {
                    throw new AuthenticationException('No fue posible autenticar la solicitud.');
                }
            } catch (AuthenticationException $exception) {
                if (! $optional) {
                    throw $exception;
                }

                // Si no había access, se conserva el refresh para una renovación posterior.
                $clearCookies = $access !== null;
            }

            if ($session !== null) {
                $guard->setUser($session->user);
                $request->setUserResolver(static fn () => $session->user);
                $request->attributes->set('auth_session', $session);
            }

            // Nunca se captura una excepción del controlador para volver a ejecutarlo.
            $response = $next($request);

            return $clearCookies ? $this->cookies->clear($response) : $response;
        } finally {
            $guard->forgetUser();
            $request->setUserResolver($previousResolver);
            $request->attributes->remove('auth_session');
        }
    }
}
