<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\MobileMutationInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Impide que una clave de identidad o un campo desconocido se descarte silenciosamente. */
abstract class MobileMutationRequest extends FormRequest
{
    public function validationData(): array
    {
        return $this->json()->all();
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function messages(): array
    {
        return MobileMutationInput::messages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key) => !str_contains($key, '.'));
            foreach (array_diff(array_keys($this->validationData()), $allowed) as $key) {
                $validator->errors()->add((string) $key, 'Este campo no pertenece al contrato de la operación.');
            }
        });
    }
}
