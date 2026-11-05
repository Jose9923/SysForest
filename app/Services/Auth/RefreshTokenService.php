<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auth\TokenPair;
use App\Repositories\Auth\AuthSessionRepository;
use App\Repositories\Auth\RefreshTokenRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use App\Services\Tenancy\TenantContextService;

class RefreshTokenService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthSessionRepository $sessions,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenService $tokens,
        private readonly SecurityEventRepository $events,
        private readonly SessionService $sessionService,
        private readonly TenantContextService $tenants,
    ) {
    }

    public function rotate(#[\SensitiveParameter] string $rawToken, string $clientType = 'web'): TokenPair
    {
        $hash = $this->hash($rawToken);
        $candidate = $hash === null ? null : $this->refreshTokens->findByHash($hash);
        $candidateSession = $candidate === null ? null : $this->sessions->find($candidate->auth_session_id);

        if (! in_array($clientType, ['web', 'mobile'], true) || $candidateSession === null || $candidateSession->client_type !== $clientType) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            $result = DB::transaction(function () use ($candidateSession, $hash, $clientType): ?TokenPair {
                // Todos los caminos de autenticación respetan usuario → sesión → token.
                $user = $this->users->findForUpdate((int) $candidateSession->user_id);
                $session = $this->sessions->find($candidateSession->id, lock: true);
                $token = $this->refreshTokens->findByHash($hash, lock: true);

                if ($session === null || $token === null || $token->auth_session_id !== $session->id
                    || $session->user_id !== (int) $user->id || $session->revoked_at !== null || $session->client_type !== $clientType) {
                    return null;
                }

                if ($token->consumed_at !== null) {
                    $this->sessions->update($session, ['revoked_at' => now(), 'revocation_reason' => 'refresh_reuse']);
                    $this->events->record('auth.refresh.reused', $user, null, ['session_id' => $session->id]);

                    // Devolver permite COMMIT. Lanzar aquí revertiría la revocación.
                    return null;
                }

                if (! $user->active || $session->expires_at->lessThanOrEqualTo(now()) || $token->expires_at->lessThanOrEqualTo(now())) {
                    return null;
                }

                if ($clientType === 'mobile') {
                    try {
                        $this->tenants->run($user, (string) $session->organization_id, fn () => $this->tenants->assertCurrentAccess($user, true));
                    } catch (AuthorizationException) {
                        $this->sessions->update($session, ['revoked_at' => now(), 'revocation_reason' => 'mobile_access_revoked']);
                        $this->events->record('auth.session.revoked', $user, $session->organization_id, [
                            'session_id' => $session->id, 'reason' => 'mobile_access_revoked',
                        ]);

                        return null;
                    }
                }

                $this->refreshTokens->consume($token);
                $refresh = bin2hex(random_bytes(32));
                $this->refreshTokens->create([
                    'auth_session_id' => $session->id,
                    'token_hash' => hash('sha256', $refresh),
                    'expires_at' => $session->expires_at,
                ]);
                $this->sessions->update($session, ['last_used_at' => now()]);

                return new TokenPair($this->tokens->issue($user, $session), $refresh, $session, $user);
            });
        } catch (ModelNotFoundException) {
            $result = null;
        }

        return $result ?? throw new AuthenticationException('No fue posible autenticar la solicitud.');
    }

    /** Logout también funciona con access vencido y es idempotente. */
    public function revokeByToken(#[\SensitiveParameter] string $rawToken, string $reason = 'logout', string $clientType = 'web'): void
    {
        $hash = $this->hash($rawToken);
        $token = $hash === null ? null : $this->refreshTokens->findByHash($hash);
        $session = $token === null ? null : $this->sessions->find($token->auth_session_id);

        if (in_array($clientType, ['web', 'mobile'], true) && $session !== null && $session->client_type === $clientType) {
            $this->sessionService->revoke($session, $reason);
        }
    }

    private function hash(#[\SensitiveParameter] string $token): ?string
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? hash('sha256', $token) : null;
    }
}
