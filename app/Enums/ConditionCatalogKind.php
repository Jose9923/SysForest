<?php

declare(strict_types=1);

namespace App\Enums;

enum ConditionCatalogKind: string
{
    case Categories = 'categories';
    case Items = 'items';

    public static function fromKey(string $key): self
    {
        return self::tryFrom($key) ?? abort(404);
    }

    public function label(): string
    {
        return $this === self::Categories ? 'Categorías de condición' : 'Condiciones forestales';
    }
}
