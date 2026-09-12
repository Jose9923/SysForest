<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\TokenPair;
use App\Repositories\AuthSessionRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RefreshTokenService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthSessionRepository $sessions,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenService $tokens,
        private readonly SecurityEventRepository $events,
        private readonly SessionService $sessionService,
    ) {
    }

    public function rotate(#[\SensitiveParameter] string $rawToken): TokenPair
    {
        $hash = $this->hash($rawToken);
        $candidate = $hash === null ? null : $this->refreshTokens->findByHash($hash);
        $candidateSession = $candidate === null ? null : $this->sessions->find($candidate->auth_session_id);

        if ($candidateSession === null) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            $result = DB::transaction(function () use ($candidateSession, $hash): ?TokenPair {
                // Todos los caminos de autenticación respetan usuario → sesión → token.
                $user = $this->users->findForUpdate((int) $candidateSession->user_id);
                $session = $this->sessions->find($candidateSession->id, lock: true);
                $token = $this->refreshTokens->findByHash($hash, lock: true);

                if ($session === null || $token === null || $token->auth_session_id !== $session->id
                    || $session->user_id !== (int) $user->id || $session->revoked_at !== null) {
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
    public function revokeByToken(#[\SensitiveParameter] string $rawToken, string $reason = 'logout'): void
    {
        $hash = $this->hash($rawToken);
        $token = $hash === null ? null : $this->refreshTokens->findByHash($hash);
        $session = $token === null ? null : $this->sessions->find($token->auth_session_id);

        if ($session !== null) {
            $this->sessionService->revoke($session, $reason);
        }
    }

    private function hash(#[\SensitiveParameter] string $token): ?string
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? hash('sha256', $token) : null;
    }
}
