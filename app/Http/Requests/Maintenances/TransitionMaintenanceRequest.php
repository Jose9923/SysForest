<?php

declare(strict_types=1);

namespace App\Http\Requests\Maintenances;

use App\Services\Maintenances\MaintenanceReadService;
use App\Validation\Maintenances\MaintenanceInput;
use Illuminate\Foundation\Http\FormRequest;

class TransitionMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->input('status') === 'verified' ? 'verify' : 'transition';
        app(MaintenanceReadService::class)->authorizeExisting($this->user(), (string) $this->route('maintenance'), $ability);

        return true;
    }

    public function rules(): array
    {
        return MaintenanceInput::transitionRules();
    }

    public function messages(): array
    {
        return MaintenanceInput::messages();
    }
}
