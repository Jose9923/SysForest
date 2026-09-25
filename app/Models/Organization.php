<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUuids;
    use SoftDeletes;

    // settings y logo_path se gestionarán por casos de uso propios; no llegan de formularios arbitrarios.
    protected $fillable = ['municipality_id', 'type', 'name', 'short_name', 'code', 'tree_code_prefix', 'email', 'phone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_public' => 'boolean', 'publication_version' => 'integer',
            'type' => OrganizationType::class, 'settings' => 'array'];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['id', 'active', 'joined_at', 'left_at'])->withTimestamps();
    }
}
