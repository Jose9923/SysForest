<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TaxonomyCatalog;
use App\Validation\TaxonomyRules;

class TaxonomyStoreRequest extends IdentityRequest
{
    public function rules(): array
    {
        return TaxonomyRules::for(TaxonomyCatalog::fromKey((string) $this->route('catalog')), update: false);
    }
}
