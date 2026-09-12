<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PasswordEmailRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255']];
    }
}
