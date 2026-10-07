<?php

declare(strict_types=1);

namespace App\Imports;

/** Identifica selecciones legibles sin sustituir la validación contra el catálogo vigente. */
final class ImportCatalogSelection
{
    public static function label(string $code, string $label): string
    {
        return $label.' ['.$code.']';
    }

    /** @return array{code:string,label:string}|null */
    public static function parse(string $value): ?array
    {
        if (preg_match('/\A(.+) \[([A-Za-z0-9_-]{1,80})\]\z/sD', $value, $matches) !== 1) {
            return null;
        }

        return ['code' => $matches[2], 'label' => $matches[1]];
    }
}
