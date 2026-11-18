<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class AuthSession extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['user_id', 'client_type', 'organization_id', 'expires_at', 'last_used_at', 'revoked_at', 'revocation_reason'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }
}
