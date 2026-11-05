<?php

declare(strict_types=1);

namespace App\Validation\Inspections;

use App\Validation\Forestry\DendrometryInput;

/** Comparte el contrato HTTP y el de servicios; los resultados del cliente se descartan. */
class TreeInspectionInput
{
    public const TEXT_FIELDS = ['flowering_development', 'fruiting_development', 'fauna_observations', 'general_observations'];
    public const DATE_FIELDS = ['flowering_date', 'fruiting_date'];
    public const LIST_FIELDS = ['physical_condition_ids', 'phytosanitary_condition_ids', 'fauna_category_ids'];

    public static function rules(bool $updating = false): array
    {
        $rules = DendrometryInput::rules() + [
            'inspection_date' => [$updating ? 'sometimes' : 'required', 'date_format:Y-m-d'],
            'inventory_tree_id' => ['nullable', 'uuid'],
            'risk_priority' => ['nullable', 'integer', 'between:1,4'],
            'risk_confirmed' => ['nullable', 'boolean'],
            'risk_methodology_id' => ['nullable', 'uuid'],
            'lock_version' => $updating ? ['required', 'integer', 'min:1', 'max:2147483646'] : ['prohibited'],
        ];
        foreach (self::TEXT_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:20000'];
        }
        foreach (self::DATE_FIELDS as $field) {
            $rules[$field] = ['nullable', 'date_format:Y-m-d'];
        }
        foreach (self::LIST_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'array', 'max:100'];
            $rules[$field.'.*'] = ['required', 'uuid', 'distinct:strict'];
        }
        foreach (['is_public', 'publication_version', 'id', 'organization_id', 'tree_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public static function filters(): array
    {
        $rules = ['risk_priority' => ['nullable', 'in:1,2,3,4,pending,inconsistent,unevaluated'],
            'condition_mode' => ['nullable', 'in:any,all'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d']];
        foreach (['inventory_id', 'species_id', 'common_name_id', 'maturity_state_id', 'tree_id'] as $field) {
            $rules[$field] = ['nullable', 'uuid'];
        }
        foreach (['diameter_class_number', 'height_class_number'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:1', 'max:9223372036854775807'];
        }
        foreach (['physical_condition_ids', 'phytosanitary_condition_ids'] as $field) {
            $rules[$field] = ['nullable', 'array', 'max:100'];
            $rules[$field.'.*'] = ['uuid', 'distinct:strict'];
        }
        foreach (['cap_cm', 'dap_m', 'total_height_m'] as $field) {
            $rules[$field.'_min'] = ['nullable', 'numeric', 'min:0', 'regex:/\A\d{1,12}(?:\.\d{1,12})?\z/D'];
            $rules[$field.'_max'] = ['nullable', 'numeric', 'min:0', 'regex:/\A\d{1,12}(?:\.\d{1,12})?\z/D'];
        }

        return $rules;
    }

    public static function messages(): array
    {
        return DendrometryInput::messages() + [
            'required' => 'El campo :attribute es obligatorio.',
            'date_format' => 'El campo :attribute debe ser una fecha válida (AAAA-MM-DD).',
            'uuid' => 'La referencia seleccionada no es válida.',
            'array' => 'Las selecciones deben enviarse como una lista.',
            'distinct' => 'No se permite seleccionar dos veces la misma opción.',
            'prohibited' => 'El campo :attribute es interno y no puede modificarse.',
            'in' => 'La opción indicada no es válida.',
            'after_or_equal' => 'La fecha final no puede preceder a la inicial.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute excede el límite técnico permitido.',
        ];
    }
}
