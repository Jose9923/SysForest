<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Validation\Mobile\MobileMutationInput;

class MobileTreeUpdateRequest extends MobileMutationRequest
{
    public function rules(): array
    {
        return MobileMutationInput::treeUpdate();
    }
}
