<?php

declare(strict_types=1);

namespace App\Models\Mobile;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Resultado durable del alta; se conserva aunque la campaña cambie después. */
class MobileInventoryReceipt extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_by' => 'integer', 'result' => 'array'];
    }
}
