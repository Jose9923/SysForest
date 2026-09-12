<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuthSession;
use App\Models\User;
use App\Repositories\AuthSessionRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;

class SessionService
{
    public function __construct(
        private readonly AuthSessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly SecurityEventRepository $events,
    ) {
    }

    public function authenticate(string $sessionId, int $userId): AuthSession
    {
        $session = $this->sessions->findForUser($sessionId, $userId);

        if ($session === null || $session->revoked_at !== null || $session->expires_at->lessThanOrEqualTo(now())
            || $session->user === null || ! $session->user->active) {
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        }

        return $session;
    }

    public function revoke(AuthSession $session, string $reason): void
    {
        DB::transaction(function () use ($session, $reason): void {
            $user = $this->users->findForUpdate((int) $session->user_id);
            $current = $this->sessions->find($session->id, lock: true);

            if ($current !== null && $current->user_id === (int) $user->id && $current->revoked_at === null) {
                $this->sessions->update($current, ['revoked_at' => now(), 'revocation_reason' => $reason]);
                $this->events->record('auth.session.revoked', $user, null, ['session_id' => $current->id, 'reason' => $reason]);
            }
        });
    }

    public function revokeAll(User $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason): void {
            $current = $this->users->findForUpdate((int) $user->id);

            foreach ($this->sessions->unrevokedForUser((int) $current->id, lock: true) as $session) {
                $this->sessions->update($session, ['revoked_at' => now(), 'revocation_reason' => $reason]);
                $this->events->record('auth.session.revoked', $current, null, ['session_id' => $session->id, 'reason' => $reason]);
            }
        });
    }
}
