<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CarbonEstimate;
use App\Models\RiskEvaluation;
use App\Models\TreeInspection;

/** Recupera solo instantáneas de la misma institución, visita y revisión. */
class InspectionDerivedResultRepository
{
    public function riskAt(TreeInspection $inspection, int $version): ?RiskEvaluation
    {
        return RiskEvaluation::query()->where('organization_id', $inspection->organization_id)
            ->where('inspection_id', $inspection->id)->where('inspection_version', $version)->first();
    }

    public function carbonAt(TreeInspection $inspection, int $version): ?CarbonEstimate
    {
        return CarbonEstimate::query()->where('organization_id', $inspection->organization_id)
            ->where('inspection_id', $inspection->id)->where('inspection_version', $version)
            ->where('species_id', $inspection->tree->species_id)
            ->whereRaw("inputs->>'growth_habit_id' IS NOT DISTINCT FROM ?::text", [$inspection->tree->growth_habit_id])
            ->orderByDesc('id')->first();
    }

    public function appendRisk(array $attributes): void
    {
        (new RiskEvaluation())->forceFill($attributes)->save();
    }

    public function appendCarbon(array $attributes): void
    {
        (new CarbonEstimate())->forceFill($attributes)->save();
    }
}
