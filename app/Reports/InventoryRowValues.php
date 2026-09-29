<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Evidence;
use App\Models\Tree;
use App\Models\TreeInspection;
use Illuminate\Support\Collection;

/** Lee únicamente relaciones precargadas; no consulta ni serializa modelos o pivotes. */
class InventoryRowValues
{
    public function shared(Tree $tree, ?TreeInspection $inspection): array
    {
        $organization = $tree->getRelation('organization');
        $municipality = $organization->getRelation('municipality');
        $department = $municipality->getRelation('department');
        $country = $department->getRelation('country');
        $species = $tree->getRelation('species');
        $names = $species?->getRelation('commonNames') ?? collect();
        $values = [
            'code' => $tree->code,
            'organization' => $organization->name,
            'country_code' => $country->code, 'country' => $country->name,
            'department_code' => $department->code, 'department' => $department->name,
            'municipality_code' => $municipality->code, 'municipality' => $municipality->name,
            'placement' => $tree->getRelation('placement')?->name,
            'longitude' => $this->coordinate($tree->getAttribute('longitude')),
            'latitude' => $this->coordinate($tree->getAttribute('latitude')),
            'common_name' => $names->first()?->name,
            'common_names' => $this->joined($names->pluck('name')),
            'scientific_name' => $species?->scientific_name,
            'family' => $species?->getRelation('family')?->scientific_name,
            'growth_habit' => $tree->getRelation('growthHabit')?->name,
            'establishment_origin' => $tree->getRelation('establishmentOrigin')?->name,
            'biogeographic_origin' => $tree->getRelation('biogeographicOrigin')?->name,
            'current_status' => $tree->current_status->value,
            'current_status_label' => $tree->current_status->label(),
            'is_protected' => $tree->is_protected, 'is_heritage' => $tree->is_heritage,
            'latest_inspection_date' => $inspection?->inspection_date?->toDateString(),
            'maturity_state' => $inspection?->getRelation('maturityState')?->name,
            'flowering_date' => $inspection?->flowering_date?->toDateString(),
            'fruiting_date' => $inspection?->fruiting_date?->toDateString(),
            'fauna' => $inspection === null ? null : $this->joined($inspection->getRelation('faunaCategories')->pluck('name')),
        ];
        foreach (['cap_cm', 'dap_m', 'radius_m', 'basal_area_m2', 'number_of_stems', 'form_factor',
            'total_height_m', 'commercial_height_m', 'commercial_volume_m3', 'total_volume_m3',
            'crown_x_m', 'crown_y_m', 'diameter_class_number', 'height_class_number'] as $field) {
            // Los casts NUMERIC del modelo producen cadenas con su escala persistida, sin pasar por float.
            $values[$field] = $inspection?->getAttribute($field);
        }
        $conditions = $inspection?->getRelation('conditions') ?? collect();
        $selected = $conditions->keyBy('code');
        foreach (InventoryExportSchema::ORIGINAL_CONDITIONS as $code => $definition) {
            // Ausencia no demuestra que se haya evaluado y descartado una condición.
            $values['condition_'.$code] = $selected->has($code) ? 'x' : null;
        }
        foreach (['physical_mechanical' => 'physical', 'phytosanitary' => 'phytosanitary'] as $type => $prefix) {
            $additional = $conditions->where('assessment_type', $type)
                ->reject(fn ($condition) => isset(InventoryExportSchema::ORIGINAL_CONDITIONS[$condition->code]))
                ->sortBy('code')->map(fn ($condition) => $condition->code.' / '
                    .$condition->getRelation('category')->name.' / '.$condition->name);
            $values[$prefix.'_additional_conditions'] = $this->joined($additional);
        }

        return $values;
    }

    /** Las URLs son controladas por el proyector; nunca se toma disk, path ni original_name. */
    public function photos(Collection $evidences, callable $url): array
    {
        $values = [];
        foreach (['EVD_FAUNA' => 'fauna_photo_urls', 'EVD_WHOLE_TREE' => 'tree_photo_urls'] as $code => $key) {
            $values[$key] = $this->joined($evidences
                ->filter(fn (Evidence $evidence) => $evidence->getRelation('evidenceType')->code === $code)
                ->map($url));
        }

        return $values;
    }

    private function joined(Collection $values): ?string
    {
        return $values->isEmpty() ? null : $values->implode("\n");
    }

    /** Las coordenadas son doubles PostGIS, no medidas NUMERIC; conserva todos sus dígitos significativos. */
    private function coordinate(mixed $value): ?string
    {
        return $value === null ? null : json_encode((float) $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
