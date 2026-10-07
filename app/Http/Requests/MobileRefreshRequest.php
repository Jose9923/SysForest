<?php

declare(strict_types=1);

namespace App\Http\Requests;

class MobileRefreshRequest extends IdentityRequest
{
    public function validationData(): array
    {
        return $this->json()->all();
    }

    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'access_token' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
