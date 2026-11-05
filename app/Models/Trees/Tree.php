<?php

declare(strict_types=1);

namespace App\Models\Trees;

use App\Enums\Trees\TreeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Catalogs\BiogeographicOrigin;
use App\Models\Catalogs\DeletionReason;
use App\Models\Catalogs\EstablishmentOrigin;
use App\Models\Evidences\Evidence;
use App\Models\Catalogs\GrowthHabit;
use App\Models\Inventories\Inventory;
use App\Models\Maintenances\Maintenance;
use App\Models\Organizations\Organization;
use App\Models\Catalogs\Placement;
use App\Models\Taxonomy\Species;
use App\Models\Inspections\TreeInspection;
use App\Models\User;

class Tree extends Model
{
    use HasUuids;
    use SoftDeletes;

    /** Los repositorios reciben exclusivamente atributos preparados por los servicios autorizados. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean', 'publication_version' => 'integer',
            'current_status' => TreeStatus::class, 'is_protected' => 'boolean', 'is_heritage' => 'boolean',
            'lock_version' => 'integer', 'accuracy_m' => 'decimal:3', 'captured_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime', 'created_by' => 'integer', 'updated_by' => 'integer',
            'deleted_by' => 'integer', 'restored_by' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inventories(): BelongsToMany
    {
        return $this->belongsToMany(Inventory::class, 'inventory_trees')
            ->withPivot(['id', 'organization_id', 'included_at', 'included_by', 'notes'])->withTimestamps();
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(Placement::class);
    }

    public function growthHabit(): BelongsTo
    {
        return $this->belongsTo(GrowthHabit::class);
    }

    public function establishmentOrigin(): BelongsTo
    {
        return $this->belongsTo(EstablishmentOrigin::class);
    }

    public function biogeographicOrigin(): BelongsTo
    {
        return $this->belongsTo(BiogeographicOrigin::class);
    }

    public function deletionReason(): BelongsTo
    {
        return $this->belongsTo(DeletionReason::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(TreeInspection::class)->orderByDesc('inspection_date')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TreeStatusHistory::class)->orderByDesc('changed_at')->orderByDesc('id');
    }

    public function locationHistories(): HasMany
    {
        return $this->hasMany(TreeLocationHistory::class)->orderByDesc('changed_at')->orderByDesc('id');
    }
}
