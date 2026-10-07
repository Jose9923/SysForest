<?php

declare(strict_types=1);

namespace App\Validation;

/** Límites compartidos por el transporte HTTP y la lectura de referencias móviles. */
final class MobileReferenceInput
{
    public static function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'q' => ['nullable', 'string', 'max:150'],
            'family_id' => ['nullable', 'uuid'],
            'condition_category_id' => ['nullable', 'uuid'],
        ];
    }

    public static function messages(): array
    {
        return [
            'integer' => 'El campo :attribute debe ser un entero.',
            'min' => 'El campo :attribute no cumple el mínimo permitido.',
            'max' => 'El campo :attribute supera el límite permitido.',
            'string' => 'El campo :attribute debe ser texto.',
            'uuid' => 'La referencia seleccionada no es válida.',
        ];
    }
}
