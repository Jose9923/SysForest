<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreeInspection extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    // El desempate de visitas y sus revisiones conserva microsegundos.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'inspection_date' => 'immutable_date', 'flowering_date' => 'immutable_date', 'fruiting_date' => 'immutable_date',
            'cap_cm' => 'decimal:6', 'total_height_m' => 'decimal:6', 'commercial_height_m' => 'decimal:6',
            'crown_x_m' => 'decimal:6', 'crown_y_m' => 'decimal:6', 'form_factor' => 'decimal:6',
            'dap_m' => 'decimal:12', 'radius_m' => 'decimal:12', 'basal_area_m2' => 'decimal:12',
            'commercial_volume_m3' => 'decimal:12', 'total_volume_m3' => 'decimal:12',
            'number_of_stems' => 'integer', 'diameter_class_number' => 'integer', 'height_class_number' => 'integer',
            'lock_version' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(Tree::class)->withTrashed();
    }

    public function inventoryTree(): BelongsTo
    {
        return $this->belongsTo(InventoryTree::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function classificationRuleVersion(): BelongsTo
    {
        return $this->belongsTo(ClassificationRuleVersion::class);
    }

    public function maturityState(): BelongsTo
    {
        return $this->belongsTo(MaturityState::class);
    }

    public function conditions(): BelongsToMany
    {
        return $this->belongsToMany(Condition::class, 'inspection_conditions', 'inspection_id', 'condition_id')
            ->using(InspectionCondition::class)->withPivot(['organization_id', 'created_at']);
    }

    public function faunaCategories(): BelongsToMany
    {
        return $this->belongsToMany(FaunaCategory::class, 'inspection_fauna', 'inspection_id', 'fauna_category_id')
            ->using(InspectionFauna::class)->withPivot(['organization_id', 'created_at']);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(InspectionRevision::class, 'inspection_id')->orderByDesc('version');
    }
}
