<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Species extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'family_id', 'scientific_name', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected $table = 'species';

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function commonNames(): HasMany
    {
        return $this->hasMany(SpeciesCommonName::class);
    }
}
