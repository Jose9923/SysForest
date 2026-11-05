<?php

declare(strict_types=1);

namespace App\Models\Inventories;

use App\Enums\Inventories\InventoryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Organizations\Organization;
use App\Models\Trees\Tree;
use App\Models\User;

class Inventory extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'starts_at', 'ends_at', 'status', 'active'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean', 'publication_version' => 'integer',
            'starts_at' => 'immutable_date', 'ends_at' => 'immutable_date',
            'status' => InventoryStatus::class, 'active' => 'boolean', 'lock_version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function trees(): BelongsToMany
    {
        return $this->belongsToMany(Tree::class, 'inventory_trees')
            ->withPivot(['id', 'organization_id', 'included_at', 'included_by', 'notes'])->withTimestamps();
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(InventoryTree::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
