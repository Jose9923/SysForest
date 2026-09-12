<?php

declare(strict_types=1);

namespace App\Catalogs;

use App\Models\CatalogEntry;

final readonly class CatalogDefinition
{
    /** @param class-string<CatalogEntry> $modelClass Modelo autorizado, nunca recibido desde HTTP. */
    public function __construct(
        public string $key,
        public string $title,
        public string $group,
        public string $description,
        public string $modelClass,
    ) {
    }
}
