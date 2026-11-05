<?php

declare(strict_types=1);

namespace App\Validation\Forestry;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Siete entradas observadas; los derivados del cliente no pertenecen a este contrato. */
final class DendrometryInput
{
    public const FIELDS = [
        'cap_cm', 'number_of_stems', 'form_factor', 'total_height_m',
        'commercial_height_m', 'crown_x_m', 'crown_y_m',
    ];

    public static function rules(): array
    {
        $rules = [];
        foreach (self::FIELDS as $field) {
            $rules[$field] = ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                $message = self::error($attribute, $value);
                if ($message !== null) {
                    $fail($message);
                }
            }];
        }

        return $rules;
    }

    public static function messages(): array
    {
        return [
            'cap_cm.gt' => 'El CAP debe ser mayor que cero.',
            'number_of_stems.gt' => 'El número de fustes debe ser un entero positivo.',
            'commercial_height_m.lte' => 'La altura comercial no puede superar la altura total.',
        ];
    }

    /** Retorna todos los campos: decimales como cadenas con seis posiciones y ausentes como NULL. */
    public static function validate(array $input): array
    {
        $values = array_intersect_key($input, array_flip(self::FIELDS));
        foreach ($values as $field => $value) {
            if (is_string($value) && trim($value) === '') {
                $values[$field] = null;
            }
        }
        $values = Validator::make($values, self::rules(), self::messages())->validate();
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;
            $normalized[$field] = $value === null ? null
                : ($field === 'number_of_stems' ? (int) $value : bcadd((string) $value, '0', 6));
        }

        if ($normalized['commercial_height_m'] !== null && $normalized['total_height_m'] !== null
            && bccomp($normalized['commercial_height_m'], $normalized['total_height_m'], 6) > 0) {
            throw ValidationException::withMessages(['commercial_height_m' => self::messages()['commercial_height_m.lte']]);
        }

        return $normalized;
    }

    private static function error(string $field, mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return 'Ingresa una cadena decimal con punto o un entero; no uses booleanos, notación científica ni valores no finitos.';
        }
        $value = (string) $value;
        if ($field === 'number_of_stems') {
            if (! preg_match('/\A[0-9]{1,19}\z/D', $value)
                || bccomp($value, '0', 0) <= 0 || bccomp($value, (string) PHP_INT_MAX, 0) > 0) {
                return 'El número de fustes debe ser un entero positivo dentro del rango BIGINT.';
            }

            return null;
        }
        $maximumIntegers = $field === 'form_factor' ? 1 : 12;
        if (! preg_match('/\A[0-9]{1,'.$maximumIntegers.'}(?:\.[0-9]{1,6})?\z/D', $value)) {
            return 'Usa punto decimal, hasta '.$maximumIntegers.' dígitos enteros y seis decimales; la entrada no se redondea.';
        }
        if (in_array($field, ['cap_cm', 'total_height_m'], true) && bccomp($value, '0', 6) <= 0) {
            return $field === 'cap_cm' ? 'El CAP debe ser mayor que cero.' : 'La altura total debe ser mayor que cero.';
        }
        if ($field === 'form_factor' && (bccomp($value, '0.45', 6) < 0 || bccomp($value, '0.70', 6) > 0)) {
            return 'El factor de forma debe estar entre 0.45 y 0.70, incluidos sus límites.';
        }

        return null;
    }
}
