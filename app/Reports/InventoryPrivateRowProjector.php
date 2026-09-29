<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Evidence;
use App\Models\Tree;
use App\Models\TreeInspection;
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
        $values['organization_code'] = $tree->getRelation('organization')->code;
        foreach (['external_number', 'coordinate_raw', 'coordinate_format', 'datum', 'accuracy_m',
            'gps_brand', 'gps_model', 'location_description'] as $field) {
            $values[$field] = $tree->getAttribute($field);
        }
        $values['location_captured_at'] = $tree->captured_at?->utc()->format('Y-m-d\TH:i:s.u\Z');
        $values['classification_rule_version'] = $inspection?->getRelation('classificationRuleVersion')?->code;
        foreach (['calculation_rule_version', 'flowering_development', 'fruiting_development',
            'fauna_observations', 'general_observations'] as $field) {
            $values[$field] = $inspection?->getAttribute($field);
        }
        $values = array_replace($values, $this->values->photos(
            $evidences,
            fn (Evidence $evidence) => route('evidences.view', ['evidence' => $evidence->id])
        ));

        return $this->schema->project($values, false);
    }
}
