<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogs;

use Illuminate\Foundation\Http\FormRequest;

class CatalogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogs.view') ?? false;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:120'], 'active' => ['nullable', 'in:0,1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000']];
    }
}
