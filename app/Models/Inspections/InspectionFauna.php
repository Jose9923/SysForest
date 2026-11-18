<?php

declare(strict_types=1);

namespace App\Models\Inspections;

use Illuminate\Database\Eloquent\Relations\Pivot;

class InspectionFauna extends Pivot
{
    protected $table = 'inspection_fauna';
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['organization_id' => 'string', 'inspection_id' => 'string', 'fauna_category_id' => 'string',
            'created_at' => 'immutable_datetime'];
    }
}
