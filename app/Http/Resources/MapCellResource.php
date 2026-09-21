<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Las celdas publican densidad o agrupación; no representan individuos ni categorías de riesgo. */
class MapCellResource extends JsonResource
{
    public function __construct($resource, private bool $heat = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return ['type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [(float) $this->longitude, (float) $this->latitude]],
            'properties' => $this->heat
                ? ['weight' => (int) $this->count]
                : ['kind' => 'cluster', 'count' => (int) $this->count,
                    'bounds' => [[(float) $this->south, (float) $this->west], [(float) $this->north, (float) $this->east]]],
        ];
    }
}
