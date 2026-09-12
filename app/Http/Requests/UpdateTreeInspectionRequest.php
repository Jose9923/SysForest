<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\TreeInspectionReadService;
use App\Validation\TreeInspectionInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTreeInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(TreeInspectionReadService::class)->authorizeExisting($this->user(), (string) $this->route('inspection'), 'update');

        return true;
    }

    public function rules(): array
    {
        return TreeInspectionInput::rules(true);
    }

    public function messages(): array
    {
        return TreeInspectionInput::messages();
    }

    /** Desmarcar todo debe retirar las relaciones, sin depender de JavaScript. */
    protected function prepareForValidation(): void
    {
        foreach (TreeInspectionInput::LIST_FIELDS as $field) {
            if (!$this->exists($field)) {
                $this->merge([$field => []]);
            }
        }
    }
}
