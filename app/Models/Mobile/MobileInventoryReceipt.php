<?php

declare(strict_types=1);

namespace App\Models\Mobile;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

/** Resultado durable del alta; se conserva aunque la campaña cambie después. */
class MobileInventoryReceipt extends Model
{
    use HasNumericIdentifiers;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_by' => 'integer', 'result' => 'array'];
    }
}
