<?php

declare(strict_types=1);

namespace App\Forestry;

use App\Enums\InventoryStatus;
use Illuminate\Validation\Rule;

final class InventoryInput
{
    public static function rules(bool $updating = false): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'regex:/\A[A-Z0-9]+(?:[-_][A-Z0-9]+)*\z/'],
            'name' => ['required', 'string', 'max:180', 'not_regex:/^\s*$/u'],
            'description' => ['nullable', 'string', 'max:4000'],
            'starts_at' => ['nullable', 'date_format:Y-m-d'],
            'ends_at' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', 'string', Rule::in(array_keys(InventoryStatus::options()))],
            'active' => ['required', 'boolean'],
            'lock_version' => $updating ? ['required', 'integer', 'min:1', 'max:2147483647'] : ['prohibited'],
            'organization_id' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
            'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'id' => ['prohibited'],
        ];
    }


    public static function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'string' => 'Introduce un texto válido.',
            'boolean' => 'Selecciona una opción válida.',
            'integer' => 'Introduce un número entero.',
            'min' => 'El valor indicado es menor que el permitido.',
            'max' => 'El valor indicado supera el límite permitido.',
            'in' => 'Selecciona una opción válida.',
            'regex' => 'Usa letras mayúsculas y números, separados únicamente por guion o guion bajo.',
            'not_regex' => 'Introduce un texto que no esté vacío.',
            'date_format' => 'Introduce una fecha válida con formato AAAA-MM-DD.',
            'prohibited' => 'Este campo se asigna en el servidor y no puede enviarse.',
        ];
    }

    public static function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(array_keys(InventoryStatus::options()))],
            'active' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'code', 'starts_at', 'created_at', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
