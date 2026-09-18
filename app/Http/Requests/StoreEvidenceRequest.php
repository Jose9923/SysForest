<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\EvidenceReadService;
use App\Validation\EvidenceInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(EvidenceReadService::class)->authorizeUpload($this->user(), $this->route('tree'), $this->route('inspection'));
    }

    public function rules(): array
    {
        return EvidenceInput::storeRules();
    }

    public function messages(): array
    {
        return EvidenceInput::messages();
    }
}
