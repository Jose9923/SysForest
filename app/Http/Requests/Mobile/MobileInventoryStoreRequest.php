<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Validation\Mobile\MobileInventoryInput;

class MobileInventoryStoreRequest extends MobileMutationRequest
{
    public function rules(): array
    {
        return MobileInventoryInput::rules();
    }

    public function messages(): array
    {
        return MobileInventoryInput::messages();
    }
}
