<?php

declare(strict_types=1);

namespace App\Models\Catalogs;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaturityRuleBand extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['rule_version_id', 'maturity_state_id', 'min_class', 'max_class'];

    protected function casts(): array
    {
        return ['min_class' => 'integer', 'max_class' => 'integer'];
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(ClassificationRuleVersion::class, 'rule_version_id');
    }

    public function maturityState(): BelongsTo
    {
        return $this->belongsTo(MaturityState::class);
    }
}
