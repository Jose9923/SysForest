<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\MaintenanceReadService;
use App\Validation\MaintenanceInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(MaintenanceReadService::class)->authorizeTree($this->user(), (string) $this->route('tree'));
    }

    public function rules(): array
    {
        return MaintenanceInput::rules();
    }

    public function messages(): array
    {
        return MaintenanceInput::messages();
    }
}
