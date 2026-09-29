<?php

declare(strict_types=1);

namespace App\Validation;

use Closure;

/** La revisión procede del formulario leído; nunca se sustituye por la versión actual del servidor. */
final class VersionInput
{
    public static function rules(bool $updating): array
    {
        return ['lock_version' => $updating ? [
            'bail', 'required',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)) {
                    $fail('La versión debe ser un entero positivo.');
                }
            },
            'integer', 'min:1', 'max:2147483646',
        ] : ['prohibited']];
    }
}
