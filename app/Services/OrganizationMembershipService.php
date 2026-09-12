<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\UserRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrganizationMembershipService
{
    public function __construct(
        private TenantContext $context,
        private TenantContextService $tenants,
        private OrganizationMembershipRepository $memberships,
        private UserRepository $users,
    ) {
    }

    public function show(User $actor, string $id): OrganizationMembership
    {
        $this->tenants->assertCurrentMembership($actor);
        $membership = $this->memberships->findForTenant($this->context->organizationId(), $id);
        Gate::forUser($actor)->authorize('view', $membership);

        return $membership;
    }

    public function add(User $actor, int $userId): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $userId): OrganizationMembership {
            $this->tenants->assertCurrentMembership($actor, true);
            Gate::forUser($actor)->authorize('create', OrganizationMembership::class);
            $this->users->find($userId);

            return $this->memberships->upsert(
                $userId,
                $this->context->organizationId(),
                ['active' => true, 'joined_at' => now(), 'left_at' => null]
            );
        });
    }

    public function setActive(User $actor, string $id, bool $active): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $id, $active): OrganizationMembership {
            $this->tenants->assertCurrentMembership($actor, true);
            $membership = $this->memberships->findForTenant($this->context->organizationId(), $id, true);
            Gate::forUser($actor)->authorize('update', $membership);

            return $this->memberships->update($membership, [
                'active' => $active,
                'joined_at' => $membership->joined_at ?? now(),
                'left_at' => $active ? null : now(),
            ]);
        });
    }
}
