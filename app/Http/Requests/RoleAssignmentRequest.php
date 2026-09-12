<?php

declare(strict_types=1);

namespace App\Http\Requests;

class RoleAssignmentRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['scope' => ['required', 'in:national,department'], 'department_id' => ['required_if:scope,department', 'nullable', 'uuid'], 'role' => ['required', 'in:SUPER_ADMIN,DEPARTMENT_ADMIN'], 'organization_id' => ['prohibited']];
    }
}
