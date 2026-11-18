<?php

declare(strict_types=1);

namespace App\Models\Trees;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class TreeLocationHistory extends Model
{
    use HasNumericIdentifiers;

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'tree_id', 'old_geometry', 'new_geometry',
        'old_coordinate_raw', 'new_coordinate_raw', 'old_metadata', 'new_metadata',
        'change_type', 'reason', 'changed_by', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_metadata' => 'array', 'new_metadata' => 'array',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(Tree::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
