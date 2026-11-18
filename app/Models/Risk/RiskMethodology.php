<?php

declare(strict_types=1);

namespace App\Models\Risk;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

/** Registro institucional inmutable; las modificaciones generan una nueva versión. */
class RiskMethodology extends Model
{
    use HasNumericIdentifiers;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
