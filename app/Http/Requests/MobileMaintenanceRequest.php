<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\MaintenanceInput;
use App\Validation\MobileMaintenanceInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MobileMaintenanceRequest extends FormRequest
{
    public function validationData(): array
    {
        return $this->query();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return MobileMaintenanceInput::rules();
    }

    public function messages(): array
    {
        return MaintenanceInput::messages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->validationData()), array_keys($this->rules())) as $key) {
                $validator->errors()->add((string) $key, 'Este filtro no pertenece a la consulta móvil.');
            }
        });
    }
}
