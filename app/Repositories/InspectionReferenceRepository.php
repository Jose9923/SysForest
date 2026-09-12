<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MaturityState;
use App\Models\Species;

class InspectionReferenceRepository
{
    /** Los filtros incluyen catálogos desactivados que todavía describen capturas históricas. */
    public function filterOptions(): array
    {
        return ['species' => Species::query()->with(['family', 'commonNames'])->orderBy('scientific_name')->get(),
            'maturityStates' => MaturityState::query()->orderBy('sort_order')->orderBy('code')->get()];
    }

    public function maturityName(?string $id): ?string
    {
        return $id === null ? null : MaturityState::query()->findOrFail($id)->name;
    }
}
