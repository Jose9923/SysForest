<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\InspectionRevisionRepository;
use App\Repositories\SecurityEventRepository;

/** La revisión completa queda estructurada por dominio y se confirma en la transacción de escritura. */
class InspectionAuditService
{
    public function __construct(private InspectionRevisionRepository $revisions, private SecurityEventRepository $events)
    {
    }

    public function snapshot(TreeInspection $inspection): array
    {
        $values = $inspection->attributesToArray();
        $groups = [
            'context' => ['tree_id', 'inventory_tree_id', 'inspection_date'],
            'measurements' => ['cap_cm', 'number_of_stems', 'form_factor', 'total_height_m', 'commercial_height_m', 'crown_x_m', 'crown_y_m'],
            'calculations' => ['dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3', 'diameter_class_number', 'height_class_number', 'maturity_state_id', 'classification_rule_version_id', 'calculation_rule_version'],
            'phenology' => ['flowering_date', 'flowering_development', 'fruiting_date', 'fruiting_development'],
            'observations' => ['general_observations'],
        ];
        $snapshot = array_intersect_key($values, array_flip(['created_by', 'updated_by', 'created_at', 'lock_version']));
        foreach ($groups as $name => $fields) {
            $snapshot[$name] = array_intersect_key($values, array_flip($fields));
        }
        $snapshot['conditions'] = $inspection->conditions->modelKeys();
        sort($snapshot['conditions']);
        $snapshot['fauna'] = ['category_ids' => $inspection->faunaCategories->modelKeys(), 'observations' => $inspection->fauna_observations];
        sort($snapshot['fauna']['category_ids']);

        return $snapshot;
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
