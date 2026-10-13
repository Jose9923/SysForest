<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MaturityState;
use App\Models\Species;
use App\Models\SpeciesCommonName;

class InspectionReferenceRepository
{
    /** Los filtros incluyen catálogos desactivados que todavía describen capturas históricas. */
    public function filterOptions(): array
    {
        return ['species' => Species::query()->with(['family', 'commonNames'])->orderBy('scientific_name')->get(),
            'common_names' => SpeciesCommonName::query()->with('species')->orderBy('name')->orderBy('id')->get(),
            'maturityStates' => MaturityState::query()->orderBy('sort_order')->orderBy('code')->get()];
    }

    public function maturityName(?string $id): ?string
    {
        return $id === null ? null : MaturityState::query()->findOrFail($id)->name;
    }
}
