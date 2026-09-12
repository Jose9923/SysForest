<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClassificationPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogs.view') ?? false;
    }

    public function rules(): array
    {
        return ['value' => ['sometimes', 'required', 'string', 'max:32'],
            'kind' => ['required_with:value', 'in:diameter,height']];
    }
}
