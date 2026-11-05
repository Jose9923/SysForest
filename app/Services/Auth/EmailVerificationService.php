<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Auth\AccountActionToken;
use App\Models\User;
use App\Repositories\Auth\AccountActionTokenRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class EmailVerificationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountActionTokenRepository $tokens,
        private readonly SecurityEventRepository $events,
        private readonly SecurityMailService $mail,
    ) {
    }

    public function send(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $user = $this->users->findForUpdate((int) $actor->id);
            if (!$user->active) {
                throw new AuthorizationException('La cuenta no está activa.');
            }
            if ($user->email_verified_at !== null) {
                return;
            }
            $token = bin2hex(random_bytes(32));
            $this->tokens->consumePending($user, 'verification');
            $this->tokens->create($user, 'verification', hash('sha256', $token));
            $this->mail->send('verification', $user, ['token' => $token, 'expires_minutes' => 60]);
        }, 3);
    }

    /** Valida el enlace sin modificar el estado de verificación de la cuenta. */
    public function preview(#[SensitiveParameter] string $token): User
    {
        $action = $this->tokens->findByHash($this->tokenHash($token), 'verification');
        if ($action === null) {
            $this->invalidToken();
        }
        $user = $this->users->find((int) $action->user_id);
        $this->assertUsable($action, $user);

        return $user;
    }

    public function verify(User $actor, #[SensitiveParameter] string $token): void
    {
        $snapshot = $this->tokens->findByHash($this->tokenHash($token), 'verification');
        if ($snapshot === null || (int) $snapshot->user_id !== (int) $actor->id) {
            $this->invalidToken();
        }

        DB::transaction(function () use ($actor, $snapshot): void {
            $user = $this->users->findForUpdate((int) $actor->id);
            $action = $this->tokens->lockById($snapshot->id);
            $this->assertUsable($action, $user);
            $before = ['email_verified_at' => $user->email_verified_at?->toISOString()];
            $this->users->update($user, ['email_verified_at' => now()]);
            $this->tokens->consumePending($user, 'verification');
            $this->events->recordChange(
                'email.verified',
                $user,
                null,
                'user',
                (string) $user->id,
                $before,
                ['email_verified_at' => $user->email_verified_at?->toISOString()]
            );
        }, 3);
    }

    private function assertUsable(AccountActionToken $token, User $user): void
    {
        if (!$user->active || $token->purpose !== 'verification' || (int) $token->user_id !== (int) $user->id
            || $token->email !== $user->email || $token->consumed_at !== null || $token->expires_at->lessThanOrEqualTo(now())) {
            $this->invalidToken();
        }
    }

    private function tokenHash(#[SensitiveParameter] string $token): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            $this->invalidToken();
        }

        return hash('sha256', $token);
    }

    private function invalidToken(): never
    {
        throw ValidationException::withMessages(['token' => 'El enlace de verificación no es válido o ya venció.']);
    }
}
