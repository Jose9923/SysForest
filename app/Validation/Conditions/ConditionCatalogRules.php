<?php

declare(strict_types=1);

namespace App\Validation\Conditions;

use App\Enums\Conditions\ConditionCatalogKind;
use App\Validation\Shared\VersionInput;

class ConditionCatalogRules
{
    public static function for(ConditionCatalogKind $catalog, bool $update = false): array
    {
        $required = $update ? ['sometimes', 'required'] : ['required'];
        $rules = [
            'code' => [...$required, 'string', 'max:80', 'regex:/\A[A-Z][A-Z0-9_]{1,79}\z/'],
            'name' => [...$required, 'string', 'max:255', 'regex:/\S/u'],
            'assessment_type' => [...$required, 'in:physical_mechanical,phytosanitary'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000000'],
            'organization_id' => ['prohibited'], 'id' => ['prohibited'],
        ];
        if ($catalog === ConditionCatalogKind::Items) {
            $rules['condition_category_id'] = [...$required, 'record_id'];
        }

        return $rules + VersionInput::rules($update);
    }
}
