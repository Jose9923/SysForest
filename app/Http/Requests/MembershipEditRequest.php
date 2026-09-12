<?php

declare(strict_types=1);

namespace App\Http\Requests;

class MembershipEditRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['active' => ['required', 'boolean'], 'role' => ['sometimes', 'required', 'in:ORGANIZATION_ADMIN,OFFICIAL'], 'organization_id' => ['prohibited'], 'user_id' => ['prohibited'], 'id' => ['prohibited']];
    }
}
