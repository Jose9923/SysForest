<?php

declare(strict_types=1);

namespace App\Validation\Trees;

use App\Enums\Maintenances\MaintenancePriority;
use App\Enums\Maintenances\MaintenanceStatus;
use App\Enums\Trees\TreeStatus;
use Illuminate\Validation\Rule;
use App\Validation\Inspections\TreeInspectionInput;

/** Contrato común de bandeja y mapa; las reglas espaciales y el scope se validan por separado. */
class TreeFilterInput
{
    public static function rules(): array
    {
        $rules = TreeInspectionInput::filters() + [
            'q' => ['nullable', 'string', 'max:150'],
            'current_status' => ['nullable', Rule::enum(TreeStatus::class)],
            'sort' => ['nullable', Rule::in(['code', 'created_at', 'scientific_name'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'trashed' => ['nullable', Rule::in(['only'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'maintenance_status' => ['nullable', Rule::enum(MaintenanceStatus::class)],
            'maintenance_priority' => ['nullable', Rule::enum(MaintenancePriority::class)],
        ];
        foreach (['department_id', 'municipality_id', 'organization_id', 'inventory_id', 'tree_id',
            'species_id', 'family_id', 'common_name_id', 'placement_id', 'growth_habit_id', 'establishment_origin_id',
            'biogeographic_origin_id', 'maintenance_type_id'] as $key) {
            $rules[$key] = ['nullable', 'uuid'];
        }
        foreach (['code', 'common_name', 'scientific_name'] as $key) {
            $rules[$key] = ['nullable', 'string', 'max:150'];
        }
        foreach (['is_protected', 'is_heritage', 'has_pending_maintenance'] as $key) {
            $rules[$key] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public static function messages(): array
    {
        return TreeInspectionInput::messages() + TreeInput::messages();
    }
}
