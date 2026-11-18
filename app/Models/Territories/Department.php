<?php

declare(strict_types=1);

namespace App\Models\Territories;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['country_id', 'code', 'name', 'active'];
    protected $hidden = ['geometry'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class);
    }
}
