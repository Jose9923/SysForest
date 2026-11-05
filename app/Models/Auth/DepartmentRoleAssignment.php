<?php

declare(strict_types=1);

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Territories\Department;
use App\Models\User;

class DepartmentRoleAssignment extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'department_id', 'role_id', 'scope', 'active', 'assigned_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'assigned_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
