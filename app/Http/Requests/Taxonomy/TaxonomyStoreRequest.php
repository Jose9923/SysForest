<?php

declare(strict_types=1);

namespace App\Http\Requests\Taxonomy;

use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Validation\Taxonomy\TaxonomyRules;
use App\Http\Requests\Shared\IdentityRequest;

class TaxonomyStoreRequest extends IdentityRequest
{
    public function rules(): array
    {
        return TaxonomyRules::for(TaxonomyCatalog::fromKey((string) $this->route('catalog')), update: false);
    }
}
