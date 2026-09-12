<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationRoleAssignment extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'membership_id', 'role_id', 'scope', 'active', 'assigned_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'assigned_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
