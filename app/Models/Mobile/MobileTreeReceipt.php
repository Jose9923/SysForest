<?php

declare(strict_types=1);

namespace App\Models\Mobile;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Confirmación durable de una captura móvil; nunca contiene tokens ni contraseñas. */
class MobileTreeReceipt extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_by' => 'integer', 'result' => 'array'];
    }
}
