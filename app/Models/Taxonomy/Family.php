<?php

declare(strict_types=1);

namespace App\Models\Taxonomy;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'scientific_name', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function species(): HasMany
    {
        return $this->hasMany(Species::class);
    }
}
