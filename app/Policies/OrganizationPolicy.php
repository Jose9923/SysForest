<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

class OrganizationPolicy
{
    public function __construct(private TenantContext $context)
    {
    }

    public function view(User $actor, Organization $organization): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $organization->id;
    }

    public function create(User $actor): bool
    {
        return Gate::forUser($actor)->allows('organizations.create');
    }

    public function update(User $actor, Organization $organization): bool
    {
        return $this->view($actor, $organization) && Gate::forUser($actor)->allows('organizations.manage', $organization);
    }
}
