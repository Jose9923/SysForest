<?php

declare(strict_types=1);

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

/** Páginas completas de lectura institucional; el cliente no puede sustituir el alcance de la ruta. */
final class MobileInventoryPageInput
{
    public static function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    public static function validate(array $input): array
    {
        $validator = Validator::make($input, self::rules(), MobileReferenceInput::messages());
        $validator->after(fn (ValidationValidator $validator) => self::rejectUnexpected($validator, $input));

        return $validator->validate();
    }

    public static function rejectUnexpected(ValidationValidator $validator, array $input): void
    {
        if (array_diff(array_keys($input), array_keys(self::rules())) !== []) {
            $validator->errors()->add('filters', 'Esta lectura solo admite page y per_page; el inventario y la institución se autorizan en el servidor.');
        }
    }
}
