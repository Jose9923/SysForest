<?php

declare(strict_types=1);

namespace App\Models\Taxonomy;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeciesCommonName extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['code', 'species_id', 'name', 'is_primary', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer', 'is_primary' => 'boolean'];
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class, 'species_id');
    }
}
