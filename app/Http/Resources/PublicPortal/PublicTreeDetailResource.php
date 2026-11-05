<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicPortal;

use Illuminate\Http\Request;

/** La ficha añade catálogos y la última visita publicada; los textos internos permanecen excluidos. */
class PublicTreeDetailResource extends PublicTreeSummaryResource
{
    public function toArray(Request $request): array
    {
        $inspection = $this->resource->getRelation('publicLatestInspection');

        return array_replace(parent::toArray($request), [
            'establishment_origin' => $this->resource->getRelation('establishmentOrigin')?->name,
            'biogeographic_origin' => $this->resource->getRelation('biogeographicOrigin')?->name,
            'latest_inspection' => $inspection === null ? null : (new PublicInspectionResource($inspection))->resolve($request),
        ]);
    }
}
