<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\Inspections\TreeInspection;
use App\Repositories\Inspections\InspectionConditionRepository;
use Illuminate\Validation\ValidationException;

class InspectionConditionService
{
    public function __construct(private InspectionConditionRepository $conditions)
    {
    }

    /** Las referencias históricas inactivas se conservan; una nueva selección debe estar disponible. */
    public function apply(TreeInspection $inspection, array $data, ?TreeInspection $previous = null): void
    {
        $ids = [];
        foreach (['physical_condition_ids' => 'physical_mechanical', 'phytosanitary_condition_ids' => 'phytosanitary'] as $field => $type) {
            $selected = $data[$field] ?? [];
            if (!$this->conditions->validate($selected, $type, $previous)) {
                throw ValidationException::withMessages([$field => 'Seleccione condiciones disponibles del grupo correspondiente.']);
            }
            $ids = array_merge($ids, $selected);
        }
        $this->conditions->sync($inspection->organization_id, $inspection->id, $ids);
    }
}
