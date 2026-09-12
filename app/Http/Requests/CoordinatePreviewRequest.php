<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class CoordinatePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['coordinate_raw' => ['required', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return TreeInput::messages();
    }
}
