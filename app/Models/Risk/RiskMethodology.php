<?php

declare(strict_types=1);

namespace App\Models\Risk;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Registro institucional inmutable; las modificaciones generan una nueva versión. */
class RiskMethodology extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
