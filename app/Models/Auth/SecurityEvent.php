<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

/** Bitácora privada de solo anexado; PostgreSQL impide modificar o borrar eventos existentes. */
class SecurityEvent extends Model
{
    use HasNumericIdentifiers;

    public $timestamps = false;
    protected $guarded = ['*'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'context' => 'array', 'before_values' => 'array', 'after_values' => 'array',
            'created_at' => 'immutable_datetime'];
    }
}
