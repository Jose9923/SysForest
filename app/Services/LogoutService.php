<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuthSessionRepository;
use Illuminate\Auth\AuthenticationException;

class LogoutService
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly TokenService $tokens,
        private readonly AuthSessionRepository $sessions,
        private readonly SessionService $sessionService,
    ) {
    }

    public function logout(
        #[\SensitiveParameter] string $accessToken,
        #[\SensitiveParameter] string $refreshToken,
    ): void {
        // El refresh permite revocar incluso cuando el access ha vencido.
        $this->refreshTokens->revokeByToken($refreshToken, 'logout');

        if ($accessToken === '') {
            return;
        }

        try {
            $claims = $this->tokens->decode($accessToken);
        } catch (AuthenticationException) {
            return;
        }

        // Una cookie refresh ausente no impide revocar un access verificado.
        $session = $this->sessions->findForUser($claims['sid'], (int) $claims['sub']);
        if ($session !== null) {
            $this->sessionService->revoke($session, 'logout');
        }
    }
}
