<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\EvidenceReadService;
use App\Validation\EvidenceInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(EvidenceReadService::class)->authorizeExisting($this->user(), (string) $this->route('evidence'), 'update');

        return true;
    }

    public function rules(): array
    {
        return EvidenceInput::updateRules();
    }

    public function messages(): array
    {
        return EvidenceInput::messages();
    }
}
