<?php

declare(strict_types=1);

namespace App\Enums\Catalogs;

/** Dominios nacionales que comparten asignación de códigos y referencias de origen. */
enum CatalogCodeKind: string
{
    case Families = 'families';
    case Species = 'species';
    case CommonNames = 'common-names';
    case Placements = 'placements';

    public function table(): string
    {
        return match ($this) {
            self::Families => 'families',
            self::Species => 'species',
            self::CommonNames => 'species_common_names',
            self::Placements => 'placements',
        };
    }

    public function referenceColumn(): string
    {
        return match ($this) {
            self::Families => 'family_id',
            self::Species => 'species_id',
            self::CommonNames => 'common_name_id',
            self::Placements => 'placement_id',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Families => 'FAMILY_',
            self::Species => 'SPECIES_',
            self::CommonNames => 'COMMON_',
            self::Placements => 'PLACEMENT_',
        };
    }
}
