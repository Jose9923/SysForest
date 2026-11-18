<?php

declare(strict_types=1);

namespace App\Models\Territories;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Organizations\Organization;

class Municipality extends Model
{
    use HasNumericIdentifiers;

    protected $fillable = ['department_id', 'code', 'name', 'active'];
    protected $hidden = ['geometry'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
