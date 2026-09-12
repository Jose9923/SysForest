<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TreeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreeStatusHistory extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['organization_id', 'tree_id', 'from_status', 'to_status', 'reason', 'changed_by', 'changed_at'];

    protected function casts(): array
    {
        return ['from_status' => TreeStatus::class, 'to_status' => TreeStatus::class, 'changed_at' => 'immutable_datetime'];
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
