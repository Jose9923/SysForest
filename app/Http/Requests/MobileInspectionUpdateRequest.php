<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\MobileMutationInput;

class MobileInspectionUpdateRequest extends MobileMutationRequest
{
    public function rules(): array
    {
        return MobileMutationInput::inspectionUpdate();
    }
}
