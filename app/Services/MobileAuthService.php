<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\TokenPair;
use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;

class MobileAuthService
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly RefreshTokenService $refreshTokens,
        private readonly TokenService $tokens,
        private readonly TenantContextService $tenants,
    ) {
    }

    public function login(string $email, #[\SensitiveParameter] string $password, string $organizationId): array
    {
        return $this->credentials($this->auth->login($email, $password, strtolower($organizationId)));
    }

    public function refresh(#[\SensitiveParameter] string $refreshToken): array
    {
        return $this->credentials($this->refreshTokens->rotate($refreshToken, 'mobile'));
    }

    public function logout(#[\SensitiveParameter] string $refreshToken): void
    {
        $this->refreshTokens->revokeByToken($refreshToken, 'mobile_logout', 'mobile');
    }

    /** La identidad se obtiene de la sesión autenticada, nunca de IDs proporcionados por el cliente. */
    public function profile(User $user, AuthSession $session): array
    {
        if ($session->client_type !== 'mobile' || (int) $session->user_id !== (int) $user->id) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }
        $organization = $this->tenants->run($user, $session->organization_id, fn () => $this->tenants->currentOrganization($user));

        return [
            'user' => ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email],
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'code' => $organization->code],
            'session_id' => $session->id,
            'session_expires_at' => $session->expires_at->toIso8601String(),
        ];
    }

    /** Única proyección que autoriza exponer las credenciales al cliente móvil. */
    private function credentials(TokenPair $pair): array
    {
        $claims = $this->tokens->decode($pair->accessToken, 'mobile');

        return array_merge([
            'token_type' => 'Bearer',
            'access_token' => $pair->accessToken,
            'expires_in' => max(0, (int) $claims['exp'] - now()->getTimestamp()),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $claims['exp']),
            'refresh_token' => $pair->refreshToken,
            'refresh_expires_at' => $pair->session->expires_at->toIso8601String(),
        ], $this->profile($pair->user, $pair->session));
    }
}
