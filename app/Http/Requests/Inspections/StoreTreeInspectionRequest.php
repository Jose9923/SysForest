<?php

declare(strict_types=1);

namespace App\Http\Requests\Inspections;

use App\Services\Inspections\TreeInspectionReadService;
use App\Validation\Inspections\TreeInspectionInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreTreeInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(TreeInspectionReadService::class)->authorizeTree($this->user(), (string) $this->route('tree'));
    }

    public function rules(): array
    {
        return TreeInspectionInput::rules();
    }

    public function messages(): array
    {
        return TreeInspectionInput::messages();
    }

    /** Un grupo sin casillas marcadas representa una selección vacía en este formulario completo. */
    protected function prepareForValidation(): void
    {
        foreach (TreeInspectionInput::LIST_FIELDS as $field) {
            if (!$this->exists($field)) {
                $this->merge([$field => []]);
            }
        }
    }
}
