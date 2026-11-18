<?php

declare(strict_types=1);

namespace App\Models\Carbon;

use Illuminate\Database\Eloquent\Model;

/** Instantánea institucional inmutable; toda corrección agrega un registro. */
class CarbonEstimate extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['organization_id' => 'string', 'species_id' => 'string', 'inspection_id' => 'string',
            'applicable' => 'boolean', 'inspection_version' => 'integer', 'inputs' => 'array', 'method' => 'array',
            'wood_density' => 'decimal:6', 'biomass_kg' => 'decimal:6', 'carbon_kg' => 'decimal:6', 'co2_kg' => 'decimal:6',
            'created_at' => 'immutable_datetime'];
    }
}
