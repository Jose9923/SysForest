<?php

declare(strict_types=1);

namespace App\Http\Requests\Inspections;

use App\Services\Forestry\ForestryAccessService;
use App\Validation\Inspections\TreeInspectionInput;
use Illuminate\Foundation\Http\FormRequest;

class TreeInspectionFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ForestryAccessService::class)->allows($this->user(), 'inspections.view');
    }

    public function rules(): array
    {
        return TreeInspectionInput::filters();
    }

    public function messages(): array
    {
        return TreeInspectionInput::messages();
    }
}
