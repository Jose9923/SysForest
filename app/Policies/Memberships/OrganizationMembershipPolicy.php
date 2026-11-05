<?php

declare(strict_types=1);

namespace App\Policies\Memberships;

use App\Models\Memberships\OrganizationMembership;
use App\Models\User;
use App\Services\Auth\PermissionService;
use App\Tenancy\TenantContext;

class OrganizationMembershipPolicy
{
    public function __construct(private TenantContext $context, private PermissionService $permissions)
    {
    }

    public function view(User $actor, OrganizationMembership $membership): bool
    {
        return $this->allows($actor, 'memberships.view', $membership);
    }

    public function create(User $actor): bool
    {
        return $this->allows($actor, 'memberships.create');
    }

    public function update(User $actor, OrganizationMembership $membership): bool
    {
        return $this->allows($actor, 'memberships.update', $membership);
    }

    public function activate(User $actor, OrganizationMembership $membership): bool
    {
        return $this->allows($actor, 'memberships.activate', $membership);
    }

    public function deactivate(User $actor, OrganizationMembership $membership): bool
    {
        return $this->allows($actor, 'memberships.deactivate', $membership);
    }

    private function allows(User $actor, string $permission, ?OrganizationMembership $membership = null): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->accessMode() !== 'department_read'
            && ($membership === null || $this->context->organizationId() === $membership->organization_id)
            && $this->permissions->allowsOrganization($actor, $permission, $this->context->organizationId());
    }
}
