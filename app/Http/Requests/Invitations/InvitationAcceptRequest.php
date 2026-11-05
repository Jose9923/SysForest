<?php

declare(strict_types=1);

namespace App\Http\Requests\Invitations;

use App\Http\Requests\Shared\IdentityRequest;

class InvitationAcceptRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/'], 'name' => ['nullable', 'string', 'max:150'], 'password' => ['nullable', 'string', 'max:128', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]];
    }
}
