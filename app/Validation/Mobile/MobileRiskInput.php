<?php

declare(strict_types=1);

namespace App\Validation\Mobile;

use Illuminate\Validation\Rule;

/** La vista previa no guarda visitas; la decisión explícita conserva el contrato de inspección. */
final class MobileRiskInput
{
    public const DECISION_FIELDS = ['risk_priority', 'risk_confirmed', 'risk_methodology_id'];

    public static function preview(array $input = []): array
    {
        return [
            'require_latest_inspection' => ['sometimes', 'boolean', Rule::prohibitedIf(empty($input['inspection_id']))],
            'tree_id' => ['nullable', 'uuid'],
            'inspection_id' => ['nullable', 'uuid'],
            'lock_version' => ['nullable', 'required_with:inspection_id', Rule::prohibitedIf(empty($input['inspection_id'])), 'integer', 'min:1', 'max:2147483646'],
            'phytosanitary_condition_ids' => ['present', 'array', 'list', 'max:100'],
            'phytosanitary_condition_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
        ];
    }

    public static function hasDecision(array $input): bool
    {
        return array_intersect(self::DECISION_FIELDS, array_keys($input)) !== [];
    }
}
