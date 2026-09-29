<?php

declare(strict_types=1);

namespace App\Validation;

use App\Enums\OrganizationType;
use App\Rules\ActiveMunicipality;
use App\Rules\UniqueOrganizationValue;
use Illuminate\Validation\Rule;

class OrganizationRules
{
    public static function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max.string' => 'El campo :attribute no debe superar :max caracteres.',
            'uuid' => 'El identificador de :attribute no tiene un formato válido.',
            'enum' => 'Selecciona un tipo de organización válido.',
            'regex' => 'El campo :attribute solo permite letras mayúsculas, números y guiones entre segmentos.',
            'email' => 'Introduce un correo electrónico válido.',
            'boolean' => 'Selecciona un estado válido.',
            'prohibited' => 'El campo :attribute no se puede modificar mediante este formulario.',
        ];
    }

    /** Reutilizadas por Form Requests y Services para proteger también llamadas fuera de HTTP. */
    public static function rules(?string $exceptId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'short_name' => ['nullable', 'string', 'max:80'],
            'municipality_id' => ['bail', 'required', 'uuid', new ActiveMunicipality()],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'code' => ['bail', 'required', 'string', 'max:40', 'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/', new UniqueOrganizationValue('code', $exceptId)],
            'tree_code_prefix' => ['bail', 'required', 'string', 'max:60', 'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/', new UniqueOrganizationValue('tree_code_prefix', $exceptId)],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'active' => ['required', 'boolean'],
            'is_public' => ['prohibited'], 'publication_version' => ['prohibited'], 'public_code' => ['prohibited'],
            'id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'settings' => ['prohibited'],
            'logo_path' => ['prohibited'],
            'deleted_at' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ] + VersionInput::rules($exceptId !== null);
    }
}
