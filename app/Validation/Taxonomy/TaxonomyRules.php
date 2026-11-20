<?php

declare(strict_types=1);

namespace App\Validation\Taxonomy;

use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Validation\Shared\VersionInput;

class TaxonomyRules
{
    public static function for(TaxonomyCatalog $catalog, bool $update = false): array
    {
        $required = $update ? ['sometimes', 'required'] : ['required'];
        $rules = [
            'code' => [...($update ? ['sometimes', 'required'] : ['sometimes', 'nullable']), 'string', 'max:80', 'regex:/\A[A-Z][A-Z0-9_]{1,79}\z/'],
            $catalog->nameColumn() => [...$required, 'string', 'max:255', 'regex:/\S/u'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000000'],
            'organization_id' => ['prohibited'], 'id' => ['prohibited'],
        ];
        if ($catalog === TaxonomyCatalog::Species) {
            $rules['family_id'] = [...$required, 'record_id'];
        } elseif ($catalog === TaxonomyCatalog::CommonNames) {
            $rules['species_id'] = [...$required, 'record_id'];
            $rules['is_primary'] = ['sometimes', 'boolean'];
        }

        return $rules + VersionInput::rules($update);
    }
}
