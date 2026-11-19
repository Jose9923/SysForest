<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Services\Audit\AuditSnapshotService;
use App\Repositories\Inspections\InspectionRevisionRepository;
use App\Repositories\Auth\SecurityEventRepository;

/** La revisión completa queda estructurada por dominio y se confirma en la transacción de escritura. */
class InspectionAuditService
{
    public function __construct(private InspectionRevisionRepository $revisions, private SecurityEventRepository $events, private AuditSnapshotService $snapshots)
    {
    }

    public function snapshot(TreeInspection $inspection): array
    {
        $values = $inspection->attributesToArray();
        $groups = [
            'context' => ['tree_id', 'inventory_tree_id', 'inspection_date'],
            'measurements' => ['cap_cm', 'number_of_stems', 'form_factor', 'total_height_m', 'commercial_height_m', 'crown_x_m', 'crown_y_m',
                'uses_clinometer', 'clinometer_distance_m', 'clinometer_elevation_degrees', 'clinometer_depression_degrees', 'clinometer_observer_height_m', 'clinometer_formula_version'],
            'calculations' => ['dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3', 'diameter_class_number', 'height_class_number', 'maturity_state_id', 'classification_rule_version_id', 'calculation_rule_version'],
            'phenology' => ['flowering_date', 'flowering_development', 'fruiting_date', 'fruiting_development'],
            'observations' => ['general_observations'],
        ];
        $snapshot = array_intersect_key($values, array_flip(['created_by', 'updated_by', 'created_at', 'lock_version']));
        foreach ($groups as $name => $fields) {
            $snapshot[$name] = array_intersect_key($values, array_flip($fields));
        }
        $evaluation = $inspection->riskEvaluation;
        $snapshot['risk'] = $evaluation ? ['evaluation_id' => $evaluation->id, 'methodology_id' => $evaluation->methodology_id,
            'priority' => $evaluation->priority, 'suggested_priority' => $evaluation->result['priority'],
            'status' => $evaluation->result['status'], 'confirmed_by' => $evaluation->confirmed_by,
            'confirmed_at' => $evaluation->confirmed_at?->toIso8601String()] : [];
        $carbon = \App\Support\Carbon\CarbonPresentation::current($inspection);
        if ($carbon !== null) {
            $snapshot['carbon'] = ['estimate_id' => $carbon->id, 'profile_id' => $carbon->profile_id,
                'status' => $carbon->status, 'method_version' => $carbon->method_version,
                'biomass_kg' => $carbon->biomass_kg, 'carbon_kg' => $carbon->carbon_kg, 'co2_kg' => $carbon->co2_kg];
        }
        $snapshot['conditions'] = $inspection->conditions->modelKeys();
        sort($snapshot['conditions']);
        $snapshot['fauna'] = ['category_ids' => $inspection->faunaCategories->modelKeys(), 'observations' => $inspection->fauna_observations];
        sort($snapshot['fauna']['category_ids']);

        return $this->snapshots->sanitize('tree_inspection', $snapshot);
    }

    public function record(User $actor, TreeInspection $inspection, array $old, string $event): void
    {
        $revision = $this->revisions->record(
            $inspection->organization_id,
            $inspection->id,
            (int) $actor->id,
            $inspection->lock_version,
            $old,
            $this->snapshot($inspection),
            $event
        );
        $this->events->recordChange(
            $event,
            $actor,
            $inspection->organization_id,
            'tree_inspection',
            $inspection->id,
            $old,
            $this->snapshot($inspection),
            ['resource_id' => $inspection->id, 'revision_id' => $revision->id]
        );
    }
}
