<?php

declare(strict_types=1);

namespace App\Http\Requests\Memberships;

use App\Services\Memberships\OrganizationMembershipService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!$this->user()) {
            return false;
        }

        $membership = app(OrganizationMembershipService::class)->show($this->user(), (string) $this->route('membership'));

        return $this->user()->can('update', $membership);
    }

    public function messages(): array
    {
        return ['required' => 'Selecciona el estado de la membresía.',
            'boolean' => 'Selecciona un estado válido.',
            'prohibited' => 'La identidad y la organización de la membresía no se pueden cambiar.'];
    }

    public function rules(): array
    {
        return ['active' => ['required', 'boolean'], 'organization_id' => ['prohibited'], 'user_id' => ['prohibited'], 'id' => ['prohibited']];
    }
}
