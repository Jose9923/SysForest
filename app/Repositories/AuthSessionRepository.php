<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AuthSession;
use Illuminate\Database\Eloquent\Collection;

class AuthSessionRepository
{
    public function create(array $attributes): AuthSession
    {
        return AuthSession::query()->create($attributes);
    }

    public function find(string $id, bool $lock = false): ?AuthSession
    {
        $query = AuthSession::query()->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function findForUser(string $id, int $userId): ?AuthSession
    {
        return AuthSession::query()->with('user')->whereKey($id)->where('user_id', $userId)->first();
    }

    /** @return Collection<int, AuthSession> */
    public function unrevokedForUser(int $userId, bool $lock = false): Collection
    {
        $query = AuthSession::query()->where('user_id', $userId)->whereNull('revoked_at')->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function countUnrevoked(): int
    {
        return AuthSession::query()->whereNull('revoked_at')->count();
    }

    public function update(AuthSession $session, array $attributes): AuthSession
    {
        $session->fill($attributes)->save();

        return $session;
    }
}
