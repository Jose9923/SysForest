<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Http\Requests\Shared\IdentityRequest;

class UserStateRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['active' => ['required', 'boolean'], 'organization_id' => ['prohibited'], 'password' => ['prohibited'], 'email' => ['prohibited']];
    }
}
