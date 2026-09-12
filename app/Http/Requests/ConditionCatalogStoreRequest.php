<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ConditionCatalogKind;
use App\Validation\ConditionCatalogRules;

class ConditionCatalogStoreRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ConditionCatalogRules::for(ConditionCatalogKind::fromKey((string) $this->route('catalog')), update: false);
    }
}
