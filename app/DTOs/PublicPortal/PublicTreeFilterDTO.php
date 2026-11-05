<?php

declare(strict_types=1);

namespace App\DTOs\PublicPortal;

use App\DTOs\Maps\MapTreeFilterDTO;
use App\DTOs\Trees\TreeFilterDTO;

/** Separa los códigos compartibles de las referencias UUID que solo utiliza el motor interno. */
final readonly class PublicTreeFilterDTO
{
    public function __construct(
        public TreeFilterDTO $trees,
        public array $publicValues,
        public MapTreeFilterDTO $map,
    ) {
    }
}
