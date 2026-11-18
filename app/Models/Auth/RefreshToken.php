<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['auth_session_id', 'token_hash', 'expires_at', 'consumed_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }

    public function authSession(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class);
    }
}
