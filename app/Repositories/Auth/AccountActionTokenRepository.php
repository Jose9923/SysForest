<?php

declare(strict_types=1);

namespace App\Repositories\Auth;

use App\Models\Auth\AccountActionToken;
use App\Models\User;

class AccountActionTokenRepository
{
    public function create(User $user, string $purpose, string $hash): AccountActionToken
    {
        return AccountActionToken::query()->create([
            'user_id' => $user->id, 'email' => $user->email, 'purpose' => $purpose,
            'token_hash' => $hash, 'expires_at' => now()->addMinutes(60),
        ]);
    }

    public function findByHash(string $hash, string $purpose): ?AccountActionToken
    {
        return AccountActionToken::query()->where('token_hash', $hash)->where('purpose', $purpose)->first();
    }

    public function lockById(string $id): AccountActionToken
    {
        return AccountActionToken::query()->lockForUpdate()->findOrFail($id);
    }

    public function consume(AccountActionToken $token): void
    {
        $token->forceFill(['consumed_at' => now()])->save();
    }

    public function countPending(string $purpose): int
    {
        return AccountActionToken::query()->where('purpose', $purpose)->whereNull('consumed_at')->count();
    }

    public function consumePending(User $user, string $purpose): int
    {
        return AccountActionToken::query()->where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }
}
