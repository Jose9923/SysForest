<?php

declare(strict_types=1);

namespace App\Http\Requests;

class RefreshRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['token' => ['prohibited'], 'refresh_token' => ['prohibited'], 'access_token' => ['prohibited']];
    }
}
