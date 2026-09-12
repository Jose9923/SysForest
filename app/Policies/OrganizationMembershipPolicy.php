<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

class OrganizationMembershipPolicy
{
    public function __construct(private TenantContext $context)
    {
    }

    public function view(User $actor, OrganizationMembership $membership): bool
    {
        return $this->sameContext($actor, $membership)
            && ((int) $membership->user_id === (int) $actor->id || Gate::forUser($actor)->allows('memberships.manage'));
    }

    public function create(User $actor): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && Gate::forUser($actor)->allows('memberships.manage');
    }

    public function update(User $actor, OrganizationMembership $membership): bool
    {
        return $this->sameContext($actor, $membership) && Gate::forUser($actor)->allows('memberships.manage');
    }

    private function sameContext(User $actor, OrganizationMembership $membership): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $membership->organization_id;
    }
}
