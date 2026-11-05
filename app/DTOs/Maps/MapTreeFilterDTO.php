<?php

declare(strict_types=1);

namespace App\DTOs\Maps;

use App\DTOs\Trees\TreeFilterDTO;

/** Compone el filtro forestal común; únicamente añade el encuadre y los parámetros espaciales. */
final readonly class MapTreeFilterDTO
{
    public function __construct(
        public TreeFilterDTO $trees,
        public ?array $bbox,
        public int $zoom = 8,
        public ?array $radius = null,
    ) {
    }
}
