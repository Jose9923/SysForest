<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use App\Validation\OrganizationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Organization::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['name', 'short_name', 'code', 'tree_code_prefix'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = in_array($field, ['code', 'tree_code_prefix'], true)
                    ? strtoupper(trim($this->input($field))) : trim($this->input($field));
            }
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return OrganizationRules::rules();
    }

    public function messages(): array
    {
        return OrganizationRules::messages();
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'type' => 'tipo', 'email' => 'correo', 'active' => 'estado',
            'code' => 'código', 'tree_code_prefix' => 'prefijo', 'municipality_id' => 'municipio'];
    }
}
