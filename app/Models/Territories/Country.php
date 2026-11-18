<?php

declare(strict_types=1);

namespace App\Models\Territories;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['code', 'name', 'iso_alpha_2', 'iso_alpha_3', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
