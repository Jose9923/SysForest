<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Services\Organizations\OrganizationService;
use App\Validation\Organizations\OrganizationRules;

class UpdateOrganizationRequest extends StoreOrganizationRequest
{
    public function authorize(): bool
    {
        if (!$this->user()) {
            return false;
        }

        $organization = app(OrganizationService::class)->show($this->user(), (string) $this->route('organization'));

        return $this->user()->can('update', $organization);
    }

    public function rules(): array
    {
        return OrganizationRules::rules((string) $this->route('organization'));
    }
}
