<?php

declare(strict_types=1);

namespace App\Validation;

use Illuminate\Validation\Rule;

/** Los límites de PostGIS se validan antes de construir el DTO; todos los valores SQL se enlazan. */
class MapInput
{
    public static function rules(bool $requireBbox): array
    {
        $rules = [
            'zoom' => ['nullable', 'integer', 'between:0,22'],
            'render_mode' => ['nullable', Rule::in(['points', 'clusters', 'heat'])],
            'scope' => ['nullable', Rule::in(['organization', 'department', 'national'])],
            'trashed' => ['prohibited'],
            'include_deleted' => ['prohibited'],
            'include_voided_trees' => ['prohibited'],
            'radius_latitude' => ['nullable', 'required_with:radius_longitude,radius_meters', 'numeric', 'between:-90,90'],
            'radius_longitude' => ['nullable', 'required_with:radius_latitude,radius_meters', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'required_with:radius_latitude,radius_longitude', 'numeric', 'between:0,20000000'],
        ];
        foreach (['west', 'south', 'east', 'north'] as $field) {
            $latitude = in_array($field, ['south', 'north'], true);
            $rules[$field] = [$requireBbox ? 'required' : 'nullable',
                'required_with:'.implode(',', array_diff(['west', 'south', 'east', 'north'], [$field])),
                'numeric', $latitude ? 'between:-90,90' : 'between:-180,180'];
        }

        return $rules;
    }

    public static function messages(): array
    {
        return TreeInput::messages() + [
            'between' => 'El campo :attribute está fuera del intervalo permitido.',
            'required_with' => 'Complete todos los valores del encuadre o del radio.',
        ];
    }
}
