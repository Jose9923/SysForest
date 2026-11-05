<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Evidences\Evidence;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use Illuminate\Support\Collection;

/** El servicio autoriza trees.view, inspections.view y evidences.view antes de invocar el lector. */
class InventoryPrivateRowProjector
{
    public function __construct(private InventoryRowValues $values, private InventoryExportSchema $schema)
    {
    }

    public function project(Tree $tree, ?TreeInspection $inspection, Collection $evidences): array
    {
        $values = $this->values->shared($tree, $inspection);
        $risk = $inspection?->getRelation('riskEvaluation');
        $carbon = \App\Support\Carbon\CarbonPresentation::current($inspection);
        $values['carbon_status'] = \App\Support\Carbon\CarbonPresentation::label($carbon?->status);
        foreach (['biomass_kg', 'carbon_kg', 'co2_kg', 'method_version', 'profile_id'] as $field) {
            $values['carbon_'.$field] = $carbon?->getAttribute($field);
        }
        $values['carbon_reference_status'] = \App\Support\Carbon\CarbonPresentation::referenceLabel($carbon?->method['profile']['validation_status'] ?? null);
        $values['carbon_reference'] = $carbon?->method['profile']['reference'] ?? null;
        $values['carbon_stock_included'] = $carbon?->status === 'estimated'
            && in_array($tree->current_status->value, ['active', 'under_observation', 'transplanted'], true);
        $values['risk_priority'] = \App\Support\Risk\RiskPresentation::label($risk);
        $values['risk_suggestion'] = $risk ? \App\Support\Risk\RiskPresentation::result($risk->result)['priority_label'] : null;
        $values['risk_methodology'] = $risk?->result['methodology_code'] ?? null;
        $values['risk_explanation'] = $risk ? \App\Support\Risk\RiskPresentation::explanation($risk) : null;
        $values['risk_confirmed_by'] = $risk?->confirmed_by;
        $values['risk_confirmed_at'] = $risk?->confirmed_at?->toIso8601String();
        $values['risk_observations'] = \App\Support\Imports\ExcelReferencePresentation::text($risk?->observations);
        $values['organization_code'] = $tree->getRelation('organization')->code;
        foreach (['external_number', 'coordinate_raw', 'coordinate_format', 'datum', 'accuracy_m',
            'gps_brand', 'gps_model', 'location_description'] as $field) {
            $values[$field] = $tree->getAttribute($field);
        }
        $values['location_captured_at'] = $tree->captured_at?->utc()->format('Y-m-d\TH:i:s.u\Z');
        $values['classification_rule_version'] = \App\Support\Catalogs\ClassificationVersionPresentation::label(
            $inspection?->getRelation('classificationRuleVersion')?->code
        );
        foreach (['calculation_rule_version', 'flowering_development', 'fruiting_development',
            'fauna_observations', 'general_observations'] as $field) {
            $values[$field] = $inspection?->getAttribute($field);
        }
        $values['general_observations'] = \App\Support\Imports\ExcelReferencePresentation::text($values['general_observations']);
        $values = array_replace($values, $this->values->photos(
            $evidences,
            fn (Evidence $evidence) => route('evidences.view', ['evidence' => $evidence->id])
        ));

        return $this->schema->project($values, false);
    }
}
