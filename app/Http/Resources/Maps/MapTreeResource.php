<?php

declare(strict_types=1);

namespace App\Http\Resources\Maps;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Proyección privada mínima: ninguna geometría cruda, observación, autoría o archivo se serializa. */
class MapTreeResource extends JsonResource
{
    public function __construct($resource, private bool $includeInspection = true, private ?string $scopeKind = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $species = $this->resource->getRelation('species');
        $placement = $this->resource->getRelation('placement');
        $commonNames = $species?->getRelation('commonNames')->pluck('name')->values()->all() ?? [];

        return [
            'type' => 'Feature',
            'id' => $this->id,
            'geometry' => $this->longitude === null || $this->latitude === null ? null
                : ['type' => 'Point', 'coordinates' => [(float) $this->longitude, (float) $this->latitude]],
            'properties' => [
                'kind' => 'tree', 'id' => $this->id, 'code' => $this->code,
                'common_name' => $commonNames[0] ?? null, 'common_names' => $commonNames,
                'scientific_name' => $species?->scientific_name,
                'status' => $this->current_status->value, 'status_label' => $this->current_status->label(),
                'placement' => $placement?->name,
                'carbon' => $this->includeInspection ? [
                    'status' => \App\Support\Carbon\CarbonPresentation::label($this->carbon_status),
                    'reference_status' => \App\Support\Carbon\CarbonPresentation::referenceLabel($this->carbon_validation_status),
                    'carbon_kg' => $this->carbon_carbon_kg, 'co2_kg' => $this->carbon_co2_kg,
                    'included_in_live_stock' => $this->carbon_status === 'estimated'
                        && in_array($this->current_status->value, ['active', 'under_observation', 'transplanted'], true),
                ] : null,
                'risk' => $this->includeInspection ? [
                    'priority' => $this->risk_priority === null ? null : (int) $this->risk_priority,
                    'color' => $this->risk_priority === null ? '#87939e' : \App\Enums\Risk\RiskPriority::from((int) $this->risk_priority)->color(),
                    'label' => $this->risk_priority !== null ? \App\Enums\Risk\RiskPriority::from((int) $this->risk_priority)->label()
                        : ($this->risk_evaluation_id === null ? 'Sin evaluar'
                            : ((json_decode($this->risk_result ?? '{}', true)['status'] ?? '') === 'inconsistent' ? 'Inconsistente' : 'Pendiente de confirmación')),
                ] : null,
                'latest_inspection' => $this->includeInspection && $this->latest_inspection_date !== null
                    ? ['date' => $this->latest_inspection_date] : null,
                'open_url' => route('maps.open', ['tree' => $this->id, 'scope' => $this->scopeKind], false),
            ],
        ];
    }
}
