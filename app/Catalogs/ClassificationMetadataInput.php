<?php

declare(strict_types=1);

namespace App\Catalogs;

final class ClassificationMetadataInput
{
    public static function rules(): array
    {
        return array_replace(CatalogInput::rules(), [
            'code' => ['prohibited'], 'algorithm' => ['prohibited'],
            'diameter_step' => ['prohibited'], 'height_step' => ['prohibited'],
            'diameter_unit' => ['prohibited'], 'height_unit' => ['prohibited'],
            'sealed_at' => ['prohibited'], 'bands' => ['prohibited'],
        ]);
    }
}
