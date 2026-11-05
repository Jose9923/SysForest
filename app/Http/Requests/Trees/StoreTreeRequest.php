<?php

declare(strict_types=1);

namespace App\Http\Requests\Trees;

use App\Validation\Trees\TreeRegistrationInput;
use App\Validation\Inspections\TreeInspectionInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreTreeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(\App\Services\Forestry\ForestryAccessService::class);

        return $access->allows($this->user(), 'trees.create') && $access->allows($this->user(), 'inspections.create');
    }

    public function rules(): array
    {
        return TreeRegistrationInput::rules();
    }

    /** Los grupos sin marcas conservan una selección vacía de la primera visita. */
    protected function prepareForValidation(): void
    {
        foreach (TreeInspectionInput::LIST_FIELDS as $field) {
            if (!$this->exists($field)) {
                $this->merge([$field => []]);
            }
        }
    }

    public function messages(): array
    {
        return TreeRegistrationInput::messages();
    }
}
