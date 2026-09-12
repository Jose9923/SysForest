<?php

declare(strict_types=1);

namespace App\Http\Requests;

class UserStateRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['active' => ['required', 'boolean'], 'organization_id' => ['prohibited'], 'password' => ['prohibited'], 'email' => ['prohibited']];
    }
}
