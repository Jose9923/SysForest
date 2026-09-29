<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassificationRuleVersion extends Model
{
    use HasUuids;

    /** Los parámetros se escriben únicamente al construir una versión desde el repositorio. */
    protected $fillable = ['name', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'diameter_step' => 'decimal:6',
            'height_step' => 'decimal:6',
            'active' => 'boolean',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
            'sealed_at' => 'immutable_datetime',
        ];
    }

    public function maturityBands(): HasMany
    {
        return $this->hasMany(MaturityRuleBand::class, 'rule_version_id')->orderBy('min_class');
    }
}
