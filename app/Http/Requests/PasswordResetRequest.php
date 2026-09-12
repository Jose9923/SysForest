<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PasswordResetRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/'], 'password' => ['required', 'string', 'max:128', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]];
    }
}
