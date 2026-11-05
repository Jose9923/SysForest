<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Validation\Mobile\MobileInventoryPageInput;
use App\Validation\Mobile\MobileReferenceInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MobileInventoryPageRequest extends FormRequest
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
        return MobileInventoryPageInput::rules();
    }

    public function messages(): array
    {
        return MobileReferenceInput::messages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => MobileInventoryPageInput::rejectUnexpected($validator, $this->query()));
    }
}
