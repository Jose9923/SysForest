<?php

declare(strict_types=1);

namespace App\Validation;

use App\Enums\TreeStatus;
use Illuminate\Validation\Rule;

/** Comparte el contrato HTTP con servicios, sin confiar en campos internos enviados por formularios. */
class TreeInput
{
    public static function rules(bool $updating = false): array
    {
        return array_merge(self::identityGuards(), self::locationRules(), [
            'external_number' => ['nullable', 'string', 'max:100'],
            'location_description' => ['nullable', 'string', 'max:2000'],
            'placement_id' => ['nullable', 'uuid'], 'species_id' => ['nullable', 'uuid'],
            'growth_habit_id' => ['nullable', 'uuid'], 'establishment_origin_id' => ['nullable', 'uuid'],
            'biogeographic_origin_id' => ['nullable', 'uuid'],
            'current_status' => [$updating ? 'sometimes' : 'required', Rule::enum(TreeStatus::class)],
            'is_protected' => ['sometimes', 'boolean'], 'is_heritage' => ['sometimes', 'boolean'],
            'inventory_ids' => [$updating ? 'sometimes' : 'required', 'array', $updating ? 'min:0' : 'min:1', 'max:50'],
            'inventory_ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'lock_version' => [$updating ? 'required' : 'prohibited', 'integer', 'min:1'],
        ]);
    }

    public static function identityGuards(): array
    {
        return array_fill_keys(['is_public', 'publication_version', 'id', 'organization_id', 'code', 'created_by', 'updated_by', 'created_at', 'updated_at', 'geometry', 'deleted_at', 'deleted_by', 'restored_by', 'restored_at', 'family_id', 'inventory_id', 'coordinate_format'], ['prohibited']);
    }

    public static function locationRules(): array
    {
        return [
            'coordinate_raw' => ['nullable', 'string', 'max:500'],
            'datum' => ['nullable', 'string', 'max:100'], 'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'gps_brand' => ['nullable', 'string', 'max:100'], 'gps_model' => ['nullable', 'string', 'max:100'],
            'captured_at' => ['nullable', 'date'],
            'location_change_type' => ['nullable', Rule::in(['correction', 'relocation', 'transplant'])],
            'location_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function statusRules(): array
    {
        return array_merge(self::identityGuards(), ['lock_version' => ['required', 'integer', 'min:1'],
            'current_status' => ['required', Rule::enum(TreeStatus::class)], 'reason' => ['nullable', 'string', 'max:2000']]);
    }

    public static function voidRules(): array
    {
        return array_merge(self::identityGuards(), ['lock_version' => ['required', 'integer', 'min:1'],
            'deletion_reason_id' => ['required', 'uuid'], 'deletion_notes' => ['nullable', 'string', 'max:2000']]);
    }

    public static function filters(): array
    {
        return TreeFilterInput::rules();
    }

    public static function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'uuid' => 'La referencia seleccionada no es válida.',
            'prohibited' => 'El campo :attribute es administrado por el sistema.', 'enum' => 'El estado seleccionado no es válido.',
            'in' => 'La selección de :attribute no es válida.', 'integer' => 'El campo :attribute debe ser un entero.',
            'numeric' => 'El campo :attribute debe ser numérico.', 'boolean' => 'La selección debe ser sí o no.',
            'date' => 'La fecha ingresada no es válida.', 'max' => 'El campo :attribute supera el límite permitido.',
            'min' => 'El campo :attribute no cumple el mínimo permitido.', 'distinct' => 'No repita el mismo inventario.',
            'array' => 'La selección debe ser una lista.', 'string' => 'El campo :attribute debe ser texto.'];
    }
}
