<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organizations\Organization;
use App\Models\Memberships\OrganizationMembership;

class OrganizationRoleAssignment extends Model
{
    use HasNumericIdentifiers;

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
