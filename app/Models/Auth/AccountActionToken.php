<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class AccountActionToken extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['user_id', 'email', 'purpose', 'token_hash', 'expires_at', 'consumed_at'];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
