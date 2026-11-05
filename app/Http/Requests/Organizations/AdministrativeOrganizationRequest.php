<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

class AdministrativeOrganizationRequest extends UpdateOrganizationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('organizations.manage-national') ?? false;
    }
}
