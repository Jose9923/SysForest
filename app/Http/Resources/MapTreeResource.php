<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
        $commonName = $species?->getRelation('commonNames')->first()?->name;

        return [
            'type' => 'Feature',
            'id' => $this->id,
            'geometry' => $this->longitude === null || $this->latitude === null ? null
                : ['type' => 'Point', 'coordinates' => [(float) $this->longitude, (float) $this->latitude]],
            'properties' => [
                'kind' => 'tree', 'id' => $this->id, 'code' => $this->code,
                'common_name' => $commonName, 'scientific_name' => $species?->scientific_name,
                'status' => $this->current_status->value, 'status_label' => $this->current_status->label(),
                'placement' => $placement?->name,
                'latest_inspection' => $this->includeInspection && $this->latest_inspection_date !== null
                    ? ['date' => $this->latest_inspection_date] : null,
                'open_url' => route('maps.open', ['tree' => $this->id, 'scope' => $this->scopeKind], false),
            ],
        ];
    }
}
