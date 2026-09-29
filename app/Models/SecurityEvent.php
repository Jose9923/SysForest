<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Bitácora privada de solo anexado; PostgreSQL impide modificar o borrar eventos existentes. */
class SecurityEvent extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $guarded = ['*'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'context' => 'array', 'before_values' => 'array', 'after_values' => 'array',
            'created_at' => 'immutable_datetime'];
    }
}
