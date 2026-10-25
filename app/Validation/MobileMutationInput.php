<?php

declare(strict_types=1);

namespace App\Validation;

use Closure;

/** Sobres estrictos para reintentos: los campos internos no se aceptan ni siquiera como NULL. */
final class MobileMutationInput
{
    public static function treeUpdate(): array
    {
        $fields = self::editable(TreeInput::rules(true), ['lock_version', 'inventory_ids', 'captured_at_local']);
        $fields['captured_at'] = ['nullable', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D'];

        return self::updateEnvelope($fields);
    }

    public static function inspectionUpdate(): array
    {
        return self::updateEnvelope(self::editable(TreeInspectionInput::rules(true), ['lock_version', 'inventory_tree_id', 'risk_priority', 'risk_confirmed', 'risk_methodology_id']));
    }

    public static function inspectionCreate(): array
    {
        $fields = self::editable(TreeInspectionInput::rules(), ['inventory_tree_id', 'risk_priority', 'risk_confirmed', 'risk_methodology_id']);

        return ['operation_id' => ['required', 'uuid'], 'inventory_id' => ['required', 'uuid']]
            + self::nested('inspection', $fields);
    }

    public static function messages(): array
    {
        return ['array' => 'El campo :attribute debe contener únicamente las claves permitidas.',
            'min' => 'Envía al menos un cambio válido.', 'uuid' => 'El campo :attribute debe ser un UUID válido.']
            + TreeInput::messages() + TreeInspectionInput::messages();
    }

    /** Una PATCH omite lo que conserva; NULL solo vacía campos que admiten NULL. */
    private static function editable(array $rules, array $excluded): array
    {
        return array_filter($rules, function (array $constraints, string $key) use ($excluded): bool {
            $root = explode('.', $key)[0];

            return !in_array('prohibited', $constraints, true) && !in_array($root, $excluded, true);
        }, ARRAY_FILTER_USE_BOTH);
    }

    private static function updateEnvelope(array $fields): array
    {
        return ['operation_id' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1', 'max:2147483646']]
            + self::nested('changes', $fields);
    }

    private static function nested(string $name, array $fields): array
    {
        $keys = array_filter(array_keys($fields), fn (string $key) => !str_contains($key, '.'));
        $rules = [$name => ['required', 'array:'.implode(',', $keys), 'min:1']];
        foreach ($fields as $key => $constraints) {
            if (in_array($key, TreeInspectionInput::LIST_FIELDS, true)) {
                $constraints[] = 'list';
            }
            // DendrometryInput distingue reglas por el nombre base del campo observado.
            $rules[$name.'.'.$key] = array_map(fn ($rule) => $rule instanceof Closure
                ? fn (string $attribute, mixed $value, Closure $fail) => $rule($key, $value, $fail)
                : $rule, $constraints);
        }

        return $rules;
    }
}
