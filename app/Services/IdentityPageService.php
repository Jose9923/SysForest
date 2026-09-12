<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\AccountActionTokenRepository;
use App\Repositories\UserRepository;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class IdentityPageService
{
    public function __construct(
        private InvitationService $invitations,
        private EmailVerificationService $verification,
        private UserRepository $users,
        private AccountActionTokenRepository $tokens,
    ) {
    }

    public function invitation(#[SensitiveParameter] mixed $token, ?User $actor): array
    {
        $token = $this->actionToken($token);
        $invitation = $this->invitations->preview($token);

        return [
            'invitation' => $invitation,
            'token' => $token,
            'existingUser' => $this->users->findByEmail($invitation->email) !== null,
            'authenticatedInvitationUser' => $actor !== null && strtolower($actor->email) === $invitation->email,
            'differentAuthenticatedUser' => $actor !== null && strtolower($actor->email) !== $invitation->email,
        ];
    }

    public function verification(#[SensitiveParameter] mixed $token, ?User $actor): array
    {
        $token = $this->actionToken($token, allowMissing: true);
        if ($token !== null) {
            $user = $this->verification->preview($token);
            if ($actor !== null && (int) $actor->id !== (int) $user->id) {
                throw ValidationException::withMessages(['token' => 'Inicia sesión con la cuenta destinataria del enlace.']);
            }
        }

        return ['verificationToken' => $token, 'invalidLink' => false, 'user' => $actor];
    }

    public function reset(#[SensitiveParameter] mixed $token): array
    {
        $token = $this->actionToken($token);
        $record = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1
            ? $this->tokens->findByHash(hash('sha256', $token), 'password_reset') : null;
        if ($record === null || $record->consumed_at !== null || $record->expires_at->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['token' => 'El enlace de recuperación no es válido o ya venció.']);
        }

        return ['token' => $token];
    }

    /** Rechaza arrays y formatos inválidos antes de usarlos en servicios o vistas. */
    private function actionToken(#[SensitiveParameter] mixed $value, bool $allowMissing = false): ?string
    {
        if ($value === null && $allowMissing) {
            return null;
        }
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw ValidationException::withMessages(['token' => 'El enlace no es válido o ya venció.']);
        }

        return $value;
    }
}
