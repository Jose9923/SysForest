<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicPortal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** La leyenda pública tiene consentimiento propio; nunca se toma caption como alternativa. */
class PublicEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->resource->getRelation('evidenceType')?->name;

        return [
            'url' => route('public.evidences.file', ['evidence' => $this->public_id], false),
            'type' => $type, 'caption' => $this->public_caption,
            'captured_at' => $this->captured_at?->toDateString(),
            // La orientación EXIF puede intercambiar dimensiones; el navegador obtiene las del derivado limpio.
            'width' => null, 'height' => null,
            'alt' => $this->public_caption !== null && $this->public_caption !== ''
                ? $this->public_caption : ($type ?? 'Fotografía publicada del árbol'),
        ];
    }
}
