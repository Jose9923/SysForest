<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\AuthSession;
use App\Models\User;
use JsonSerializable;

final readonly class TokenPair implements JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        #[\SensitiveParameter] public string $refreshToken,
        public AuthSession $session,
        public User $user,
    ) {
    }

    /** Los secretos solo salen mediante AuthCookies, nunca al serializar el resultado. */
    public function jsonSerialize(): array
    {
        return ['session_id' => $this->session->id, 'user_id' => $this->user->id];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
