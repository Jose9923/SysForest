<?php

declare(strict_types=1);

namespace App\Models\Inventories;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organizations\Organization;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;

class InventoryTree extends Model
{
    use HasUuids;

    protected $table = 'inventory_trees';

    protected $fillable = ['organization_id', 'inventory_id', 'tree_id', 'included_at', 'included_by', 'notes'];

    protected function casts(): array
    {
        return ['included_at' => 'immutable_datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(Tree::class);
    }

    public function inspections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TreeInspection::class);
    }

    public function includedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'included_by');
    }
}
