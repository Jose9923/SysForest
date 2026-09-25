<?php

declare(strict_types=1);

namespace App\Validation;

use Closure;
use Illuminate\Validation\Rule;

class PublicationInput
{
    public static function rules(bool $evidence = false): array
    {
        return [
            'is_public' => ['required', 'boolean'],
            'publication_version' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)) {
                    $fail('La versión de publicación debe ser un entero positivo.');
                }
            }, 'integer', 'min:1', 'max:2147483646'],
            'public_caption' => $evidence ? ['nullable', 'string', 'max:2000'] : ['prohibited'],
        ] + array_fill_keys(['id', 'organization_id', 'tree_id', 'inventory_id', 'tree_inspection_id',
            'maintenance_id', 'public_code', 'public_id', 'updated_by', 'created_by', 'lock_version',
            'caption', 'notes', 'file', 'disk', 'path', 'deleted_at'], ['prohibited']);
    }

    public static function filters(): array
    {
        return ['kind' => ['nullable', Rule::in(['organizations', 'inventories', 'trees', 'inspections', 'evidences'])],
            'q' => ['nullable', 'string', 'max:120'], 'is_public' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'revisions_page' => ['nullable', 'integer', 'min:1', 'max:100000']];
    }

    public static function messages(): array
    {
        return ['required' => 'Este dato es obligatorio.', 'boolean' => 'Selecciona una opción válida.',
            'integer' => 'La versión o página debe ser un entero.', 'min' => 'El valor es menor que el permitido.',
            'max' => 'El valor supera el límite permitido.', 'string' => 'Introduce un texto válido.',
            'in' => 'Selecciona un tipo de registro válido.', 'prohibited' => 'Este dato se gestiona por otro flujo autorizado.'];
    }
}
