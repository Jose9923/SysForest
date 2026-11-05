<?php

declare(strict_types=1);

namespace App\Http\Requests\Trees;

use App\Validation\Trees\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTreeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\Trees\TreeReadService::class)->authorizeExisting($this->user(), (string) $this->route('tree'), 'update', false);
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
