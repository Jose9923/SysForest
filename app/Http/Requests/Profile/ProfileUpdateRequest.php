<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Http\Requests\Shared\IdentityRequest;

class ProfileUpdateRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'email' => ['prohibited'], 'active' => ['prohibited'], 'role' => ['prohibited']];
    }
}
