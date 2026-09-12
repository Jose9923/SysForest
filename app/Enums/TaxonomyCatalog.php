<?php

declare(strict_types=1);

namespace App\Enums;

enum TaxonomyCatalog: string
{
    case Families = 'families';
    case Species = 'species';
    case CommonNames = 'common-names';

    public static function fromKey(string $key): self
    {
        return self::tryFrom($key) ?? abort(404);
    }

    public function label(): string
    {
        return match ($this) {
            self::Families => 'Familias taxonómicas',
            self::Species => 'Especies',
            self::CommonNames => 'Nombres comunes',
        };
    }

    public function nameColumn(): string
    {
        return $this === self::CommonNames ? 'name' : 'scientific_name';
    }
}
