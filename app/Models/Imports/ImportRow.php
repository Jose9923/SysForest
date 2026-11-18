<?php

declare(strict_types=1);

namespace App\Models\Imports;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

/** Conserva la fila recibida y la proyección validada, sin convertir decimales en float. */
class ImportRow extends Model
{
    use HasNumericIdentifiers;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'raw_values' => 'array', 'reader_errors' => 'array',
            'normalized_values' => 'array', 'errors' => 'array'];
    }
}
