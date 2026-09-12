<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTreeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\TreeReadService::class)->authorizeExisting($this->user(), (string) $this->route('tree'), 'update', false);
    }

    public function rules(): array
    {
        return TreeInput::rules(true);
    }

    public function messages(): array
    {
        return TreeInput::messages();
    }
}
