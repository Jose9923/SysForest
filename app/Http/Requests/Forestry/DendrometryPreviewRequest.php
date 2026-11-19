<?php

declare(strict_types=1);

namespace App\Http\Requests\Forestry;

use App\Services\Forestry\ForestryAccessService;
use App\Services\Inspections\TreeInspectionReadService;
use App\Validation\Forestry\DendrometryInput;
use App\Validation\Forestry\ClinometerInput;
use App\Validation\Inspections\TreeInspectionInput;
use Illuminate\Foundation\Http\FormRequest;

class DendrometryPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(ForestryAccessService::class);
        if (!$access->allows($this->user(), 'inspections.create') && !$access->allows($this->user(), 'inspections.update')) {
            return false;
        }
        if ($this->filled('inspection_id') && is_string($this->input('inspection_id'))) {
            app(TreeInspectionReadService::class)->authorizeExisting($this->user(), $this->input('inspection_id'), 'update');
        }

        return true;
    }

    public function rules(): array
    {
        return DendrometryInput::rules() + ClinometerInput::rules() + ['inspection_id' => ['nullable', 'record_id']];
    }

    public function messages(): array
    {
        return TreeInspectionInput::messages();
    }
}
