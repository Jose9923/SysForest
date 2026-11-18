<?php

declare(strict_types=1);

namespace App\Models\Inspections;

use Illuminate\Database\Eloquent\Relations\Pivot;

class InspectionCondition extends Pivot
{
    protected $table = 'inspection_conditions';
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['organization_id' => 'string', 'inspection_id' => 'string', 'condition_id' => 'string',
            'created_at' => 'immutable_datetime'];
    }
}
