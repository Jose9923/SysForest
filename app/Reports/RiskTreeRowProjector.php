<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Tree;
use App\Models\TreeInspection;
use App\Support\ExcelReferencePresentation;
use App\Support\RiskPresentation;
use LogicException;

/** Presenta evaluaciones persistidas y relaciones precargadas; no calcula ni confirma riesgo. */
class RiskTreeRowProjector
{
    public function __construct(private RiskTreeSchema $schema)
    {
    }

    public function project(Tree $tree, ?TreeInspection $inspection): array
    {
        $organization = $tree->getRelation('organization');
        $species = $tree->getRelation('species');
        $risk = $inspection?->getRelation('riskEvaluation');
        $campaign = $inspection?->getRelation('inventoryTree');
        $inventory = $campaign?->getRelation('inventory');
        if ($campaign !== null && ($campaign->organization_id !== $tree->organization_id || $campaign->tree_id !== $tree->id
            || $inventory?->organization_id !== $tree->organization_id)) {
            throw new LogicException('La campaña de la última visita no pertenece al árbol y organización.');
        }
        $names = $species?->getRelation('commonNames') ?? collect();
        $result = $risk === null ? null : RiskPresentation::result($risk->result);

        return $this->schema->project([
            'code' => $tree->code,
            'common_names' => $names->isEmpty() ? null : $names->pluck('name')->implode("\n"),
            'scientific_name' => $species?->scientific_name,
            'location_description' => ExcelReferencePresentation::text($tree->location_description),
            'inventory' => $inventory === null ? null : $inventory->code.' · '.$inventory->name,
            'current_status_label' => $tree->current_status->label(),
            'latest_inspection_date' => $inspection?->inspection_date?->toDateString(),
            'risk_priority' => RiskPresentation::label($risk),
            'risk_suggestion' => $result['priority_label'] ?? null,
            'risk_methodology' => $risk?->result['methodology_code'] ?? null,
            'organization' => $organization->name,
            'municipality' => $organization->getRelation('municipality')?->name,
            'risk_confirmed_by' => $risk?->priority === null ? null : $risk->getRelation('confirmer')?->name,
            'risk_confirmed_at' => $risk?->priority === null ? null : $risk->confirmed_at?->utc()->toIso8601String(),
            'risk_explanation' => $risk === null ? null : RiskPresentation::explanation($risk),
            'risk_observations' => ExcelReferencePresentation::text($risk?->observations),
        ]);
    }
}
