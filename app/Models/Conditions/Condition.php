<?php

declare(strict_types=1);

namespace App\Models\Conditions;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Condition extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'assessment_type', 'condition_category_id', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ConditionCategory::class, 'condition_category_id');
    }
}
