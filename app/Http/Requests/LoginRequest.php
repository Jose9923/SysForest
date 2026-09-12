<?php

declare(strict_types=1);

namespace App\Http\Requests;

class LoginRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'max:128']];
    }
}
