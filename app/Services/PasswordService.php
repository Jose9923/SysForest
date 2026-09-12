<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountActionToken;
use App\Models\User;
use App\Repositories\AccountActionTokenRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;
use SensitiveParameter;

class PasswordService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountActionTokenRepository $tokens,
        private readonly SessionService $sessions,
        private readonly SecurityEventRepository $events,
        private readonly SecurityMailService $mail,
    ) {
    }

    /** La respuesta externa es idéntica para cuentas inexistentes, inactivas o con correo pendiente. */
    public function requestReset(string $email): void
    {
        $email = Str::lower(trim($email));
        $token = bin2hex(random_bytes(32));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254']])->fails()) {
            return;
        }

        try {
            $snapshot = $this->users->findByEmail($email);
            if ($snapshot === null) {
                return;
            }
            DB::transaction(function () use ($snapshot, $email, $token): void {
                $user = $this->users->findForUpdate((int) $snapshot->id);
                if (!$user->active || $user->email !== $email) {
                    return;
                }
                $this->tokens->consumePending($user, 'password_reset');
                $this->tokens->create($user, 'password_reset', hash('sha256', $token));
                $this->mail->send('password_reset', $user, ['token' => $token, 'expires_minutes' => 60]);
            }, 3);
        } catch (Throwable) {
            // Incluso un fallo de correo o infraestructura conserva la respuesta sin enumerar cuentas.
            Log::warning('No fue posible procesar una solicitud de recuperación de acceso.');
        }
    }

    public function reset(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): void
    {
        $this->validatePassword($password);
        $snapshot = $this->tokens->findByHash($this->tokenHash($token), 'password_reset');
        if ($snapshot === null) {
            $this->invalidToken();
        }

        DB::transaction(function () use ($snapshot, $password): void {
            $user = $this->users->findForUpdate((int) $snapshot->user_id);
            $current = $this->tokens->lockById($snapshot->id);
            $this->assertUsable($current, $user);
            $this->users->update($user, ['password' => $password, 'remember_token' => Str::random(60)]);
            $this->tokens->consumePending($user, 'password_reset');
            $this->sessions->revokeAll($user, 'password_reset');
            $this->events->record('password.reset', $user, null, []);
        }, 3);
    }

    public function change(User $actor, #[SensitiveParameter] string $currentPassword, #[SensitiveParameter] string $password): void
    {
        $this->validatePassword($password);
        DB::transaction(function () use ($actor, $currentPassword, $password): void {
            $user = $this->users->findForUpdate((int) $actor->id);
            if (!$user->active || !Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            $this->users->update($user, ['password' => $password, 'remember_token' => Str::random(60)]);
            $this->tokens->consumePending($user, 'password_reset');
            $this->sessions->revokeAll($user, 'password_changed');
            $this->events->record('password.changed', $user, null, []);
        }, 3);
    }

    private function validatePassword(#[SensitiveParameter] string $password): void
    {
        Validator::make(['password' => $password], [
            'password' => ['required', 'string', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate();
    }

    private function assertUsable(AccountActionToken $token, User $user): void
    {
        if (!$user->active || $token->purpose !== 'password_reset' || (int) $token->user_id !== (int) $user->id
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
        throw ValidationException::withMessages(['token' => 'El enlace de recuperación no es válido o ya venció.']);
    }
}
