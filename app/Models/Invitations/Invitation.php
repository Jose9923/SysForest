<?php

declare(strict_types=1);

namespace App\Models\Invitations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organizations\Organization;
use App\Models\Auth\Role;
use App\Models\User;

class Invitation extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'email', 'role_id', 'scope', 'invited_by', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
