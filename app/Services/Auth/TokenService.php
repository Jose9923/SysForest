<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Auth\AuthSession;
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
        $clientType = $session->client_type;
        if ((int) $session->user_id !== (int) $user->id || ! $user->active
            || $session->revoked_at !== null || $session->expires_at->lessThanOrEqualTo(now())
            || ! in_array($clientType, ['web', 'mobile'], true)
            || ($clientType === 'mobile' && ! Str::isUuid((string) $session->organization_id))) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            $this->jwt->factory()->emptyClaims()->customClaims([]);
            $claims = [
                'iss' => (string) config('security.issuer'),
                'aud' => [$this->audience($clientType)],
                'sid' => $session->id,
                'exp' => min(
                    now()->addMinutes((int) config('security.access_ttl_minutes'))->getTimestamp(),
                    $session->expires_at->getTimestamp(),
                ),
            ];
            if ($clientType === 'mobile') {
                $claims['client'] = 'mobile';
                $claims['org'] = $session->organization_id;
            }

            return $this->jwt->claims($claims)->fromUser($user);
        } finally {
            $this->clear();
        }
    }

    /** @return array<string, mixed> */
    public function decode(#[\SensitiveParameter] string $token, string $clientType = 'web'): array
    {
        if ($token === '' || strlen($token) > 8192 || ! in_array($clientType, ['web', 'mobile'], true)) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        try {
            // Cada superficie obtiene la credencial explícitamente: nunca se invoca parseToken.
            $payload = $this->jwt->setToken($token)->getPayload()->toArray();
            $subject = $payload['sub'] ?? null;
            $validSubject = is_string($subject) && preg_match('/^[1-9][0-9]*$/D', $subject) === 1
                && strlen($subject) <= strlen((string) PHP_INT_MAX)
                && (strlen($subject) < strlen((string) PHP_INT_MAX) || strcmp($subject, (string) PHP_INT_MAX) <= 0);
            $audience = $payload['aud'] ?? null;
            $validContext = $clientType === 'mobile'
                ? ($payload['client'] ?? null) === 'mobile' && is_string($payload['org'] ?? null) && Str::isUuid($payload['org'])
                : ! isset($payload['client']) && ! isset($payload['org']);

            if (! $validSubject || ! $validContext
                || ! is_string($payload['sid'] ?? null) || ! Str::isUuid($payload['sid'])
                || ($payload['iss'] ?? null) !== (string) config('security.issuer')
                || $audience !== [$this->audience($clientType)]
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

    private function audience(string $clientType): string
    {
        return (string) config($clientType === 'mobile' ? 'security.mobile_audience' : 'security.audience');
    }

    private function clear(): void
    {
        $this->jwt->unsetToken()->claims([]);
        $this->jwt->factory()->emptyClaims()->customClaims([]);
    }
}
