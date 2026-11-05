<?php

declare(strict_types=1);

namespace App\Http\Requests\Trees;

use App\Validation\Trees\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class TreeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\Forestry\ForestryAccessService::class)->allows($this->user(), 'trees.view');
    }

    public function rules(): array
    {
        return TreeInput::filters();
    }

    public function messages(): array
    {
        return TreeInput::messages();
    }
}
