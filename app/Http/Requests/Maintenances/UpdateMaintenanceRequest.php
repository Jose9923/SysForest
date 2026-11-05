<?php

declare(strict_types=1);

namespace App\Http\Requests\Maintenances;

use App\Services\Maintenances\MaintenanceReadService;
use App\Validation\Maintenances\MaintenanceInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(MaintenanceReadService::class)->authorizeExisting($this->user(), (string) $this->route('maintenance'), 'update');

        return true;
    }

    public function rules(): array
    {
        return MaintenanceInput::rules(true);
    }

    public function messages(): array
    {
        return MaintenanceInput::messages();
    }
}
