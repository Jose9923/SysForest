<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class OrganizationPolicy
{
    public function __construct(private TenantContext $context, private PermissionService $permissions)
    {
    }

    public function view(User $actor, Organization $organization): bool
    {
        if (!$this->sameContext($actor, $organization)) {
            return false;
        }

        if ($this->context->accessMode() === 'department_read') {
            return $this->permissions->allowsDepartment($actor, 'organizations.view', $organization->municipality->department_id);
        }

        return $this->permissions->allowsOrganization($actor, 'organizations.view', $organization->id);
    }

    public function create(User $actor): bool
    {
        return $this->permissions->allowsNational($actor, 'organizations.create');
    }

    public function update(User $actor, Organization $organization): bool
    {
        return $this->sameContext($actor, $organization) && $this->context->accessMode() !== 'department_read'
            && $this->permissions->allowsOrganization($actor, 'organizations.manage', $organization->id);
    }

    private function sameContext(User $actor, Organization $organization): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $organization->id;
    }
}
