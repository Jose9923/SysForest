<?php

declare(strict_types=1);

namespace App\Http\Requests\Mobile;

use App\Validation\Mobile\MobileRiskInput;

class MobileRiskPreviewRequest extends MobileMutationRequest
{
    public function rules(): array
    {
        return MobileRiskInput::preview($this->validationData());
    }

    public function messages(): array
    {
        return [
            'phytosanitary_condition_ids.present' => 'Envía la lista de condiciones fitosanitarias, aunque esté vacía.',
            'lock_version.required_with' => 'La corrección requiere la versión actual de la inspección.',
            'lock_version.prohibited' => 'La versión corresponde únicamente a una inspección existente.',
        ] + parent::messages();
    }
}
