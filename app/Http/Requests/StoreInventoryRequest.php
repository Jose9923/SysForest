<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Forestry\InventoryInput;
use App\Services\ForestryAccessService;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ForestryAccessService::class)->allows($this->user(), 'inventories.create');
    }

    public function rules(): array
    {
        return InventoryInput::rules();
    }

    public function messages(): array
    {
        return InventoryInput::messages();
    }
}
