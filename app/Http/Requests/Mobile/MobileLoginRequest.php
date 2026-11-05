<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Http\Requests\Shared\IdentityRequest;

class MobileLoginRequest extends IdentityRequest
{
    public function validationData(): array
    {
        return $this->json()->all();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'organization_id' => ['required', 'uuid'],
            'user_id' => ['prohibited'],
            'access_token' => ['prohibited'],
            'refresh_token' => ['prohibited'],
        ];
    }
}
