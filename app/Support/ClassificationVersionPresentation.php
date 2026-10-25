<?php

declare(strict_types=1);

namespace App\Support;

/** Etiqueta visible independiente del identificador histórico utilizado por el cálculo. */
final class ClassificationVersionPresentation
{
    public static function label(?string $code): ?string
    {
        return $code === 'EXCEL_V1' ? 'Clasificación forestal · versión 1' : $code;
    }
}
