<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Shared\IdentityRequest;

class LoginRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'max:128']];
    }
}
