<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ForestryAccessService;
use App\Validation\MaintenanceInput;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ForestryAccessService::class)->allows($this->user(), 'maintenances.view');
    }

    public function rules(): array
    {
        return MaintenanceInput::filters();
    }

    public function messages(): array
    {
        return MaintenanceInput::messages();
    }
}
