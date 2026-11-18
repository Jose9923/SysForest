<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mobile;

use App\Services\Auth\SessionService;
use App\Services\Tenancy\TenantContextService;
use App\Services\Auth\TokenService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileJwt
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly SessionService $sessions,
        private readonly TenantContextService $tenants,
        private readonly TenantContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $previousResolver = $request->getUserResolver();
        $guard->forgetUser();
        $request->setUserResolver(static fn () => null);
        $request->attributes->remove('auth_session');
        $this->context->clear();

        try {
            $authorization = $request->header('Authorization');
            // Un único Bearer estricto; cookies, query y cuerpo nunca autentican esta API.
            if (! is_string($authorization) || strlen($authorization) > 8200
                || preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD', $authorization, $matches) !== 1) {
                throw new AuthenticationException('No fue posible autenticar la solicitud.');
            }

            $claims = $this->tokens->decode($matches[1], 'mobile');
            $session = $this->sessions->authenticate($claims['sid'], (int) $claims['sub']);
            if ($session->client_type !== 'mobile' || app(\App\Repositories\Identifiers\IdentifierRepository::class)->external('organizations', $session->organization_id) !== $claims['org']) {
                throw new AuthenticationException('No fue posible autenticar la solicitud.');
            }

            $this->tenants->activate($session->user, $session->organization_id);
            $guard->setUser($session->user);
            $request->setUserResolver(static fn () => $session->user);
            $request->attributes->set('auth_session', $session);

            return $next($request);
        } finally {
            $guard->forgetUser();
            $request->setUserResolver($previousResolver);
            $request->attributes->remove('auth_session');
            $this->context->clear();
        }
    }
}
