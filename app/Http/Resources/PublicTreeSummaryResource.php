<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Solo relaciones precargadas y campos forestales; nunca serializa el modelo ni sus pivotes. */
class PublicTreeSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $species = $this->resource->getRelation('species');
        $organization = $this->resource->getRelation('organization');
        $municipality = $organization->getRelation('municipality');
        $department = $municipality->getRelation('department');
        $country = $department->getRelation('country');
        $feature = (new PublicTreeMapResource($this->resource))->resolve($request);

        return [
            'code' => $this->code,
            'common_name' => $feature['properties']['common_name'],
            'common_names' => $feature['properties']['common_names'],
            'scientific_name' => $species?->scientific_name,
            'family' => $species?->getRelation('family')?->scientific_name,
            'growth_habit' => $this->resource->getRelation('growthHabit')?->name,
            'placement' => $feature['properties']['placement'],
            'status' => $this->current_status->value, 'status_label' => $this->current_status->label(),
            'is_protected' => (bool) $this->is_protected, 'is_heritage' => (bool) $this->is_heritage,
            'organization' => ['code' => $organization->public_code, 'name' => $organization->name],
            'municipality' => ['code' => $country->code.'~'.$department->code.'~'.$municipality->code, 'name' => $municipality->name],
            'department' => ['code' => $country->code.'~'.$department->code, 'name' => $department->name],
            'country' => ['code' => $country->code, 'name' => $country->name],
            'geometry' => $feature['geometry'], 'latest_inspection' => $feature['properties']['latest_inspection'],
            'url' => $feature['properties']['open_url'],
        ];
    }
}
