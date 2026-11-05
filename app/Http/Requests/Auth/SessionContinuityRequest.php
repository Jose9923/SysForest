<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

class SessionContinuityRequest extends RefreshRequest
{
    public function rules(): array
    {
        return parent::rules() + ['expected_context' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }
}
