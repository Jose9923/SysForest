<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ForestryAccessService;
use App\Services\TreeInspectionReadService;
use App\Validation\DendrometryInput;
use App\Validation\TreeInspectionInput;
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
        return DendrometryInput::rules() + ['inspection_id' => ['nullable', 'uuid']];
    }

    public function messages(): array
    {
        return TreeInspectionInput::messages();
    }
}
