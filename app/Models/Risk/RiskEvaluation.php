<?php

declare(strict_types=1);

namespace App\Models\Risk;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

/** Registro institucional inmutable; las modificaciones generan una nueva versión. */
class RiskEvaluation extends Model
{
    use HasNumericIdentifiers;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function methodology(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RiskMethodology::class, 'methodology_id');
    }

    public function confirmer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by')->select('id', 'name');
    }

    protected function casts(): array
    {
        return ['result' => 'array', 'priority' => 'integer', 'inspection_version' => 'integer', 'confirmed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
