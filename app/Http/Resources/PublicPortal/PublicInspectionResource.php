<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicPortal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Conserva decimales como cadenas: publica resultados existentes, sin recalcular fórmulas forestales. */
class PublicInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $measurements = [];
        foreach ([
            'cap_cm' => ['Circunferencia a la altura del pecho', 'cm'],
            'dap_m' => ['Diámetro a la altura del pecho', 'm'],
            'radius_m' => ['Radio', 'm'],
            'total_height_m' => ['Altura total', 'm'],
            'commercial_height_m' => ['Altura comercial', 'm'],
            'crown_x_m' => ['Diámetro de copa X', 'm'],
            'crown_y_m' => ['Diámetro de copa Y', 'm'],
            'basal_area_m2' => ['Área basal', 'm²'],
            'commercial_volume_m3' => ['Volumen comercial', 'm³'],
            'total_volume_m3' => ['Volumen total', 'm³'],
            'form_factor' => ['Factor de forma', null],
            'number_of_stems' => ['Número de fustes', null],
        ] as $field => [$label, $unit]) {
            if ($this->resource->getAttribute($field) !== null) {
                $measurements[] = ['label' => $label, 'value' => (string) $this->resource->getAttribute($field), 'unit' => $unit];
            }
        }
        $conditions = $this->resource->getRelation('conditions');

        return [
            'date' => $this->inspection_date->toDateString(),
            'measurements' => $measurements,
            'diameter_class_number' => $this->diameter_class_number,
            'height_class_number' => $this->height_class_number,
            'maturity_state' => $this->resource->getRelation('maturityState')?->name,
            'physical_conditions' => $conditions->where('assessment_type', 'physical_mechanical')->pluck('name')->values()->all(),
            'phytosanitary_conditions' => $conditions->where('assessment_type', 'phytosanitary')->pluck('name')->values()->all(),
            'fauna' => $this->resource->getRelation('faunaCategories')->pluck('name')->values()->all(),
            'flowering_date' => $this->flowering_date?->toDateString(),
            'fruiting_date' => $this->fruiting_date?->toDateString(),
        ];
    }
}
