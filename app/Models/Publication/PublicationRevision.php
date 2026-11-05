<?php

declare(strict_types=1);

namespace App\Models\Publication;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/** Historial privado con sujetos referenciados mediante claves foráneas reales. */
class PublicationRevision extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $guarded = ['*'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['version' => 'integer', 'changed_at' => 'immutable_datetime', 'changed_by' => 'integer',
            'old_values' => 'array', 'new_values' => 'array'];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
