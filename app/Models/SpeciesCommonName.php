<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeciesCommonName extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'species_id', 'name', 'is_primary', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer', 'is_primary' => 'boolean'];
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class, 'species_id');
    }
}
