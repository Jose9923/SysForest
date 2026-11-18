<?php

declare(strict_types=1);

namespace App\Models\Inspections;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class InspectionRevision extends Model
{
    use HasNumericIdentifiers;

    public $timestamps = false;
    protected $guarded = ['*'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['version' => 'integer', 'changed_by' => 'integer', 'changed_at' => 'immutable_datetime',
            'old_values' => 'array', 'new_values' => 'array'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(TreeInspection::class, 'inspection_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
