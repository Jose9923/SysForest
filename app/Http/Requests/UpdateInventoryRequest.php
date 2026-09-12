<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Forestry\InventoryInput;
use App\Services\InventoryService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(InventoryService::class)->form($this->user(), (string) $this->route('inventory'));

        return true;
    }

    public function rules(): array
    {
        return InventoryInput::rules(true);
    }

    public function messages(): array
    {
        return InventoryInput::messages();
    }
}
