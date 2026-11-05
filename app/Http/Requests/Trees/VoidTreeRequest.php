<?php

declare(strict_types=1);

namespace App\Http\Requests\Trees;

use App\Validation\Trees\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class VoidTreeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\Trees\TreeReadService::class)->authorizeExisting($this->user(), (string) $this->route('tree'), 'void', false);
    }

    public function rules(): array
    {
        return TreeInput::voidRules();
    }

    public function messages(): array
    {
        return TreeInput::messages();
    }
}
