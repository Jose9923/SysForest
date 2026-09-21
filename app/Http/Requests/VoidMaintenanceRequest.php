<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\MaintenanceReadService;
use App\Validation\MaintenanceInput;
use Illuminate\Foundation\Http\FormRequest;

class VoidMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(MaintenanceReadService::class)->authorizeExisting($this->user(), (string) $this->route('maintenance'), 'void');

        return true;
    }

    public function rules(): array
    {
        return MaintenanceInput::lifecycleRules();
    }

    public function messages(): array
    {
        return MaintenanceInput::messages();
    }
}
