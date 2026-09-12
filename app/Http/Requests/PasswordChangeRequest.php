<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PasswordChangeRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:128'], 'password' => ['required', 'string', 'max:128', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]];
    }
}
