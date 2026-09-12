<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\RefreshToken;

class RefreshTokenRepository
{
    public function create(array $attributes): RefreshToken
    {
        return RefreshToken::query()->create($attributes);
    }

    public function findByHash(string $hash, bool $lock = false): ?RefreshToken
    {
        $query = RefreshToken::query()->where('token_hash', $hash);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function consume(RefreshToken $token): void
    {
        $token->consumed_at = now();
        $token->save();
    }
}
