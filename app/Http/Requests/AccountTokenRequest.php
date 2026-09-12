<?php

declare(strict_types=1);

namespace App\Http\Requests;

class AccountTokenRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/']];
    }
}
