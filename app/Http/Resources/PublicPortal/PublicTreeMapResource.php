<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicPortal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Lista blanca independiente: la identidad pública compuesta nunca contiene una PK interna. */
class PublicTreeMapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $species = $this->resource->getRelation('species');
        $organization = $this->resource->getRelation('organization');
        $commonNames = $species?->getRelation('commonNames')->pluck('name')->values()->all() ?? [];

        return [
            'type' => 'Feature',
            'id' => $organization->public_code.'~'.$this->code,
            'geometry' => $this->longitude === null || $this->latitude === null ? null
                : ['type' => 'Point', 'coordinates' => [(float) $this->longitude, (float) $this->latitude]],
            'properties' => [
                'kind' => 'tree', 'code' => $this->code,
                'common_name' => $commonNames[0] ?? null, 'common_names' => $commonNames,
                'scientific_name' => $species?->scientific_name,
                'status' => $this->current_status->value, 'status_label' => $this->current_status->label(),
                'placement' => $this->resource->getRelation('placement')?->name,
                'latest_inspection' => $this->latest_inspection_date === null ? null : ['date' => $this->latest_inspection_date],
                'open_url' => route('public.trees.show', ['organization' => $organization->public_code, 'tree' => $this->code], false),
            ],
        ];
    }
}
