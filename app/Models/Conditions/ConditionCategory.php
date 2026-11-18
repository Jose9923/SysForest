<?php

declare(strict_types=1);

namespace App\Models\Conditions;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConditionCategory extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['code', 'name', 'assessment_type', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(Condition::class);
    }
}
