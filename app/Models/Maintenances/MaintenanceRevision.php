<?php

declare(strict_types=1);

namespace App\Models\Maintenances;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class MaintenanceRevision extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $guarded = ['*'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['version' => 'integer', 'changed_by' => 'integer', 'changed_at' => 'immutable_datetime',
            'old_values' => 'array', 'new_values' => 'array'];
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class)->withTrashed();
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
