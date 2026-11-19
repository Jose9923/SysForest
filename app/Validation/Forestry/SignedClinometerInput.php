<?php

declare(strict_types=1);

namespace App\Validation\Forestry;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** La lectura inferior conserva su signo; AF corresponde a la altura comercial del fuste. */
final class SignedClinometerInput
{
    public const METHOD = 'signed_angles';

    public const VERSION = 'INCLINOMETER_V2';

    public const OPERANDS = [
        'clinometer_distance_m', 'clinometer_elevation_degrees',
        'clinometer_stem_elevation_degrees', 'clinometer_lower_degrees',
    ];

    /** Solo valida campos presentes: los PATCH completan el contexto existente después del recibo. */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::OPERANDS as $field) {
            $rules[$field] = ['nullable', function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                $signed = $field === 'clinometer_lower_degrees';
                $pattern = $signed ? '/\A-?[0-9]{1,12}(?:\.[0-9]{1,6})?\z/D' : '/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/D';
                if ((!is_string($value) && !is_int($value)) || !preg_match($pattern, (string) $value)) {
                    $fail($signed
                        ? 'Usa un decimal finito con punto y hasta seis decimales; conserva el signo de la lectura inferior.'
                        : 'Usa un decimal finito positivo o cero, con punto y hasta seis decimales.');

                    return;
                }
                if ($field === 'clinometer_distance_m') {
                    if (bccomp((string) $value, '0', 6) <= 0) {
                        $fail('La distancia horizontal debe ser mayor que cero.');
                    }
                } elseif (bccomp((string) $value, '90', 6) >= 0 || ($signed && bccomp((string) $value, '-90', 6) <= 0)) {
                    $fail($signed
                        ? 'La lectura inferior debe ser mayor que −90 y menor que 90 grados.'
                        : 'El ángulo superior debe estar entre 0 y menos de 90 grados.');
                }
            }];
        }

        return $rules;
    }

    /** El selector es transitorio y no agrega un default a la petición validada ni al hash. */
    public static function selectorRules(): array
    {
        return ['clinometer_method' => ['nullable', 'in:legacy,signed_angles']];
    }

    public static function method(array $input): string
    {
        $selected = Validator::make(array_intersect_key($input, ['clinometer_method' => true]), self::selectorRules())->validate();

        return $selected['clinometer_method'] ?? 'legacy';
    }

    /** Retorna AT/AF redondeadas sin alterar otras mediciones. */
    public static function resolve(array $input): array
    {
        $values = array_intersect_key($input, array_flip(self::OPERANDS));
        foreach ($values as $field => $value) {
            if (is_string($value) && trim($value) === '') {
                $values[$field] = null;
            }
        }
        $rules = self::rules();
        foreach (self::OPERANDS as $field) {
            array_unshift($rules[$field], 'required');
        }
        $values = Validator::make($values, $rules, ['required' => 'Completa la distancia y las tres lecturas angulares.'])->validate();
        $operands = [];
        foreach (self::OPERANDS as $field) {
            $operands[$field] = bcadd((string) $values[$field], '0', 6);
        }
        $distance = (float) $operands['clinometer_distance_m'];
        $lower = tan(deg2rad((float) $operands['clinometer_lower_degrees']));
        $total = $distance * (tan(deg2rad((float) $operands['clinometer_elevation_degrees'])) - $lower);
        $stem = $distance * (tan(deg2rad((float) $operands['clinometer_stem_elevation_degrees'])) - $lower);
        $heights = [];
        foreach (['total_height_m' => $total, 'commercial_height_m' => $stem] as $field => $height) {
            if (!is_finite($height) || $height < 0 || $height >= 1_000_000_000_000) {
                throw ValidationException::withMessages([$field => 'Las lecturas producen una altura fuera del rango admitido.']);
            }
            $normalized = number_format(round($height, 6, PHP_ROUND_HALF_UP), 6, '.', '');
            if ($field === 'total_height_m' && bccomp($normalized, '0', 6) <= 0) {
                throw ValidationException::withMessages([$field => 'La altura calculada debe ser mayor que cero; revisa las lecturas superior e inferior.']);
            }
            if (bccomp($normalized, '1000000000000', 6) >= 0) {
                throw ValidationException::withMessages([$field => 'La altura redondeada supera el rango admitido.']);
            }
            $heights[$field] = $normalized;
        }
        if (bccomp($heights['commercial_height_m'], $heights['total_height_m'], 6) > 0) {
            throw ValidationException::withMessages(['commercial_height_m' => 'La altura comercial (fuste) no puede superar la altura total.']);
        }

        return $operands + $heights + ['clinometer_formula_version' => self::VERSION];
    }
}
