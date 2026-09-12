<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWT;

class TokenService
{
    public function __construct(private readonly JWT $jwt)
    {
    }

    public function issue(User $user, AuthSession $session): string
    {
        if ((int) $session->user_id !== (int) $user->id || ! $user->active
            || $session->revoked_at !== null || $session->expires_at->lessThanOrEqualTo(now())) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            $this->jwt->factory()->emptyClaims()->customClaims([]);

            return $this->jwt->claims([
                'iss' => (string) config('security.issuer'),
                'aud' => [(string) config('security.audience')],
                'sid' => $session->id,
                'exp' => min(
                    now()->addMinutes((int) config('security.access_ttl_minutes'))->getTimestamp(),
                    $session->expires_at->getTimestamp(),
                ),
            ])->fromUser($user);
        } finally {
            $this->clear();
        }
    }

    /** @return array<string, mixed> */
    public function decode(#[\SensitiveParameter] string $token): array
    {
        if ($token === '' || strlen($token) > 8192) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            // Nunca se invoca parseToken: query, cuerpo y encabezados no son credenciales web.
            $payload = $this->jwt->setToken($token)->getPayload()->toArray();
            $subject = $payload['sub'] ?? null;
            $validSubject = is_string($subject) && preg_match('/^[1-9][0-9]*$/D', $subject) === 1
                && strlen($subject) <= strlen((string) PHP_INT_MAX)
                && (strlen($subject) < strlen((string) PHP_INT_MAX) || strcmp($subject, (string) PHP_INT_MAX) <= 0);
            $audience = $payload['aud'] ?? null;

            if (! $validSubject
                || ! is_string($payload['sid'] ?? null) || ! Str::isUuid($payload['sid'])
                || ($payload['iss'] ?? null) !== (string) config('security.issuer')
                || $audience !== [(string) config('security.audience')]
                || ($payload['prv'] ?? null) !== sha1(User::class)) {
                throw new AuthenticationException('No fue posible autenticar la solicitud.');
            }

            return $payload;
        } catch (JWTException) {
            // No se encadena la excepción del proveedor: puede incluir material del token.
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        } finally {
            $this->clear();
        }
    }

    private function clear(): void
    {
        $this->jwt->unsetToken()->claims([]);
        $this->jwt->factory()->emptyClaims()->customClaims([]);
    }
}
