<?php

declare(strict_types=1);

namespace App\Repositories\Auth;

use App\Models\Auth\AuthSession;
use Illuminate\Database\Eloquent\Collection;

class AuthSessionRepository
{
    public function create(array $attributes): AuthSession
    {
        return AuthSession::query()->create($attributes);
    }

    public function find(string $id, bool $lock = false): ?AuthSession
    {
        if (!\Illuminate\Support\Str::isUuid($id) && !\App\Support\Identifiers\RecordIdentifier::isValid($id)) {
            return null;
        }

        $query = AuthSession::query()->where(\Illuminate\Support\Str::isUuid($id) ? 'external_id' : 'id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function findForUser(string $id, int $userId): ?AuthSession
    {
        if (!\Illuminate\Support\Str::isUuid($id) && !\App\Support\Identifiers\RecordIdentifier::isValid($id)) {
            return null;
        }

        return AuthSession::query()->with('user')->where(\Illuminate\Support\Str::isUuid($id) ? 'external_id' : 'id', $id)->where('user_id', $userId)->first();
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
