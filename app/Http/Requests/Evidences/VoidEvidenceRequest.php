<?php

declare(strict_types=1);

namespace App\Http\Requests\Evidences;

use App\Services\Evidences\EvidenceReadService;
use App\Validation\Evidences\EvidenceInput;
use Illuminate\Foundation\Http\FormRequest;

class VoidEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(EvidenceReadService::class)->authorizeExisting($this->user(), (string) $this->route('evidence'), 'delete');

        return true;
    }

    public function rules(): array
    {
        return EvidenceInput::lifecycleRules();
    }

    public function messages(): array
    {
        return EvidenceInput::messages();
    }
}
