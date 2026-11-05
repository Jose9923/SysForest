<?php

declare(strict_types=1);

namespace App\Http\Requests\Conditions;

use App\Enums\Conditions\ConditionCatalogKind;
use App\Validation\Conditions\ConditionCatalogRules;
use App\Http\Requests\Shared\IdentityRequest;

class ConditionCatalogUpdateRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ConditionCatalogRules::for(ConditionCatalogKind::fromKey((string) $this->route('catalog')), update: true);
    }
}
