<?php

declare(strict_types=1);

namespace App\Catalogs;

/** Reglas de entrada compartidas por HTTP y los servicios de escritura. */
final class CatalogInput
{
    public static function rules(bool $updating = false, ?string $catalogKey = null): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'regex:/\A'.self::codePattern($catalogKey).'\z/'],
            'name' => ['required', 'string', 'max:180', 'not_regex:/^\s*$/u'],
            'description' => ['nullable', 'string', 'max:4000'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'organization_id' => ['prohibited'],
        ] + \App\Validation\Shared\VersionInput::rules($updating);
    }

    /** Los cuatro códigos de ubicación conservan el contrato de historiales y clientes anteriores. */
    public static function codePattern(?string $catalogKey = null): string
    {
        $standard = '[A-Z][A-Z0-9_]{1,79}';

        return $catalogKey === 'location-change-types'
            ? '(?:'.$standard.'|correction|relocation|transplant|removal)'
            : $standard;
    }
}
