<?php

declare(strict_types=1);

namespace App\Http\Requests\Trees;

use App\Validation\Trees\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class IncludeTreeInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\Trees\TreeReadService::class)->authorizeExisting($this->user(), (string) $this->route('tree'), 'update', false);
    }

    public function rules(): array
    {
        return array_merge(TreeInput::identityGuards(), ['lock_version' => ['required', 'integer', 'min:1'], 'inventory_ids' => ['required', 'array', 'min:1', 'max:50'], 'inventory_ids.*' => ['required', 'uuid', 'distinct'], 'notes' => ['nullable', 'string', 'max:2000']]);
    }

    public function messages(): array
    {
        return TreeInput::messages();
    }
}
