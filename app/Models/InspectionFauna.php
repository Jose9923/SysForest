<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class InspectionFauna extends Pivot
{
    protected $table = 'inspection_fauna';
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
