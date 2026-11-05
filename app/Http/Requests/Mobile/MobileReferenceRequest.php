<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Validation\Mobile\MobileReferenceInput;
use Illuminate\Foundation\Http\FormRequest;

class MobileReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return MobileReferenceInput::rules($this->routeIs('mobile.inventories'));
    }

    public function messages(): array
    {
        return MobileReferenceInput::messages();
    }
}
