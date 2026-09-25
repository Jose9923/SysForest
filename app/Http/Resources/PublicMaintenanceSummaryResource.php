<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Historial ejecutado/verificado sin programación futura, responsables ni resultados internos. */
class PublicMaintenanceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->getRelation('maintenanceType')?->name,
            'status' => $this->status->value, 'status_label' => $this->status->label(),
            'performed_at' => $this->performed_date?->toDateString(),
        ];
    }
}
