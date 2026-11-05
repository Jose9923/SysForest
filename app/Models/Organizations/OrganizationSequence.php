<?php

declare(strict_types=1);

namespace App\Models\Organizations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSequence extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'name', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
