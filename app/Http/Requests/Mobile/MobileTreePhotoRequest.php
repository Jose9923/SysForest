<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Validation\Mobile\MobileTreePhotoInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class MobileTreePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** Los parámetros de consulta nunca sustituyen los campos del multipart. */
    public function validationData(): array
    {
        return array_replace_recursive($this->request->all(), $this->allFiles());
    }

    public function rules(): array
    {
        return MobileTreePhotoInput::rules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => MobileTreePhotoInput::rejectUnexpected($validator, $this->validationData()));
    }

    public function messages(): array
    {
        return MobileTreePhotoInput::messages();
    }
}
