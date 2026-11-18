<?php

declare(strict_types=1);

namespace App\Models\Memberships;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Organizations\Organization;
use App\Models\Auth\OrganizationRoleAssignment;
use App\Models\User;

class OrganizationMembership extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['user_id', 'organization_id', 'active', 'joined_at', 'left_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'joined_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function roleAssignment(): HasOne
    {
        return $this->hasOne(OrganizationRoleAssignment::class, 'membership_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
