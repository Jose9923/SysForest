<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\MobileReferenceInput;
use Illuminate\Foundation\Http\FormRequest;

class MobileReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return MobileReferenceInput::rules();
    }

    public function messages(): array
    {
        return MobileReferenceInput::messages();
    }
}
