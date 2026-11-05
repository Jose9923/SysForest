<?php

declare(strict_types=1);

namespace App\Http\Requests\Invitations;

use App\Http\Requests\Shared\IdentityRequest;

class InvitationCreateRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:254'], 'role' => ['required', 'in:ORGANIZATION_ADMIN,OFFICIAL'], 'organization_id' => ['prohibited']];
    }
}
