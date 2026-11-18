<?php

declare(strict_types=1);

namespace App\Models\Mobile;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

/** Conserva el resultado exacto confirmado aunque existan revisiones posteriores. */
class MobileMutationReceipt extends Model
{
    use HasNumericIdentifiers;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_by' => 'integer', 'result' => 'array'];
    }
}
