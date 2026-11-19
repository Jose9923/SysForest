<?php

declare(strict_types=1);

namespace App\Validation\Forestry;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Conserva observaciones y método; nunca modifica una petición ni un recibo de sincronización. */
final class ClinometerInput
{
    public const VERSION = 'INCLINOMETER_V1';

    public const OPERANDS = [
        'clinometer_distance_m', 'clinometer_elevation_degrees',
        'clinometer_depression_degrees', 'clinometer_observer_height_m',
    ];

    public const SIGNED_OPERANDS = ['clinometer_stem_elevation_degrees', 'clinometer_lower_degrees'];

    /** Persistentes: el selector transitorio nunca se proyecta ni se audita. */
    public const FIELDS = ['uses_clinometer', ...self::OPERANDS, ...self::SIGNED_OPERANDS];

    public static function enabled(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    public static function rules(): array
    {
        $rules = ['uses_clinometer' => ['nullable', 'boolean'], 'clinometer_formula_version' => ['prohibited']];
        foreach (self::OPERANDS as $field) {
            $rules[$field] = ['nullable', function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                if ((!is_string($value) && !is_int($value))
                    || !preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/D', (string) $value)) {
                    $fail('Usa un decimal finito con punto, hasta doce dígitos enteros y seis decimales.');

                    return;
                }
                $angle = str_ends_with($field, '_degrees');
                if ($angle && bccomp((string) $value, '90', 6) >= 0) {
                    $fail('El ángulo debe estar entre 0 y menos de 90 grados.');
                } elseif (!$angle && bccomp((string) $value, '0', 6) <= 0) {
                    $fail('La distancia horizontal y la altura de la persona deben ser mayores que cero.');
                }
            }];
        }

        return $rules + SignedClinometerInput::rules() + SignedClinometerInput::selectorRules();
    }

    /** Solo la escritura o vista previa llama a esta normalización, después del contrato de entrada. */
    public static function resolve(array $input): array
    {
        $values = array_intersect_key($input, array_flip([...self::FIELDS, 'clinometer_method']));
        foreach ($values as $field => $value) {
            if (is_string($value) && trim($value) === '') {
                $values[$field] = null;
            }
        }
        $rules = self::rules();
        $method = SignedClinometerInput::method($values);
        if (self::enabled($values['uses_clinometer'] ?? null)) {
            foreach ($method === SignedClinometerInput::METHOD ? SignedClinometerInput::OPERANDS : self::OPERANDS as $field) {
                array_unshift($rules[$field], 'required');
            }
        }
        $values = Validator::make($values, $rules, [
            'required' => 'Completa las cuatro mediciones del inclinómetro.',
            'uses_clinometer.boolean' => 'Indica si utilizaste inclinómetro.',
        ])->validate();
        $mode = $values['uses_clinometer'] ?? null;
        if (!self::enabled($mode)) {
            if ($mode === null && collect([...self::OPERANDS, ...self::SIGNED_OPERANDS])->contains(fn (string $field): bool => ($values[$field] ?? null) !== null)) {
                throw ValidationException::withMessages(['uses_clinometer' => 'Activa el inclinómetro para registrar sus mediciones.']);
            }

            return ['uses_clinometer' => $mode === null ? null : false]
                + array_fill_keys([...self::OPERANDS, ...self::SIGNED_OPERANDS], null) + ['clinometer_formula_version' => null];
        }

        if ($method === SignedClinometerInput::METHOD) {
            return ['uses_clinometer' => true] + SignedClinometerInput::resolve($values)
                + ['clinometer_depression_degrees' => null, 'clinometer_observer_height_m' => null];
        }
        foreach (self::SIGNED_OPERANDS as $field) {
            if (($values[$field] ?? null) !== null) {
                throw ValidationException::withMessages(['clinometer_method' => 'Selecciona el método de ángulos firmados para registrar estas lecturas.']);
            }
        }

        $operands = [];
        foreach (self::OPERANDS as $field) {
            $operands[$field] = bcadd((string) $values[$field], '0', 6);
        }
        $distance = (float) $operands['clinometer_distance_m'];
        $elevation = $distance * tan(deg2rad((float) $operands['clinometer_elevation_degrees']));
        $depression = $distance * tan(deg2rad((float) $operands['clinometer_depression_degrees']));
        $heights = [
            'total_height_m' => $elevation + $depression,
            'commercial_height_m' => $elevation + (float) $operands['clinometer_observer_height_m'],
        ];
        foreach ($heights as $field => $height) {
            if (!is_finite($height) || $height < 0 || $height >= 1_000_000_000_000) {
                throw ValidationException::withMessages([$field => 'Las mediciones del inclinómetro producen una altura fuera del rango admitido.']);
            }
            $heights[$field] = number_format(round($height, 6, PHP_ROUND_HALF_UP), 6, '.', '');
        }
        // Conserva límites, precisión y comercial ≤ total de las alturas manuales.
        $validated = DendrometryInput::validate($heights);

        return ['uses_clinometer' => true] + $operands + ['clinometer_formula_version' => self::VERSION]
            + array_intersect_key($validated, $heights) + array_fill_keys(self::SIGNED_OPERANDS, null);
    }

    /** Un cliente anterior puede reenviar alturas iguales al corregir otra observación. */
    public static function legacyManualChange(array $input, array $current): bool
    {
        if (array_key_exists('uses_clinometer', $input) || !self::enabled($current['uses_clinometer'] ?? null)) {
            return false;
        }
        foreach (['total_height_m', 'commercial_height_m'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if (is_string($value) && trim($value) === '') {
                $value = null;
            }
            $stored = $current[$field] ?? null;
            if ($value === null || $stored === null) {
                if ($value !== $stored) {
                    return true;
                }
                continue;
            }
            if ((!is_string($value) && !is_int($value))
                || !preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/D', (string) $value)
                || bccomp((string) $value, (string) $stored, 6) !== 0) {
                return true;
            }
        }

        return false;
    }
}
