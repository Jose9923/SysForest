<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;

class SwitchOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function messages(): array
    {
        return ['organization_id.required' => 'Selecciona una organización.',
            'organization_id.record_id' => 'El identificador de la organización no es válido.'];
    }

    public function rules(): array
    {
        return ['organization_id' => ['required', 'record_id']];
    }
}
