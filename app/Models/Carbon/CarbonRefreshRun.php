<?php

declare(strict_types=1);

namespace App\Models\Carbon;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;

class CarbonRefreshRun extends Model
{
    use HasNumericIdentifiers;

    protected $guarded = ['*'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['requested_by' => 'integer', 'reference_version' => 'integer', 'total' => 'integer',
            'processed' => 'integer', 'estimates_added' => 'integer', 'requested_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
