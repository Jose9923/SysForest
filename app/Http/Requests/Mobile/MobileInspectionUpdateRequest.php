<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Validation\Mobile\MobileMutationInput;

class MobileInspectionUpdateRequest extends MobileMutationRequest
{
    public function rules(): array
    {
        return MobileMutationInput::inspectionUpdate();
    }
}
