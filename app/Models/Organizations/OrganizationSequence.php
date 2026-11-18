<?php

declare(strict_types=1);

namespace App\Models\Organizations;

use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSequence extends Model
{
    use HasNumericIdentifiers;

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
