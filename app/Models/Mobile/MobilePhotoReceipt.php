<?php

declare(strict_types=1);

namespace App\Models\Mobile;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

/** Confirmación durable de una foto: permite reintentar sin crear otra evidencia o archivo. */
class MobilePhotoReceipt extends Model
{
    use HasNumericIdentifiers;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_by' => 'integer', 'result' => 'array'];
    }
}
