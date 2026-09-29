<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** La autoría de la carga es permanente; confirmar registra un actor independiente. */
class ImportBatch extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'file_size' => 'integer', 'lock_version' => 'integer',
            'total_rows' => 'integer', 'valid_rows' => 'integer',
            'invalid_rows' => 'integer', 'imported_rows' => 'integer',
            'confirmed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
