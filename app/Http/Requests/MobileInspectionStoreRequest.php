<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Validation\MobileMutationInput;

class MobileInspectionStoreRequest extends MobileMutationRequest
{
    public function rules(): array
    {
        return MobileMutationInput::inspectionCreate();
    }
}
