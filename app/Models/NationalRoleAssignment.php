<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationalRoleAssignment extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'role_id', 'scope', 'active', 'assigned_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'assigned_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
