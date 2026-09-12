<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTreeLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\TreeReadService::class)->authorizeExisting($this->user(), (string) $this->route('tree'), 'update', false);
    }

    public function rules(): array
    {
        return array_merge(TreeInput::identityGuards(), TreeInput::locationRules(), ['lock_version' => ['required', 'integer', 'min:1']]);
    }

    public function messages(): array
    {
        return TreeInput::messages();
    }
}
