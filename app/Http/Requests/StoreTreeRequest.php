<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\TreeInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreTreeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Services\ForestryAccessService::class)->allows($this->user(), 'trees.create');
    }

    public function rules(): array
    {
        return TreeInput::rules();
    }

    public function messages(): array
    {
        return TreeInput::messages();
    }
}
