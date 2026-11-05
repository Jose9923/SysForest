<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Models\User;
use App\Repositories\Auth\RoleRepository;
use Illuminate\Support\Facades\Gate;
use App\Services\Tenancy\TenantContextService;

class MembershipPageService
{
    public function __construct(private TenantContextService $tenants, private RoleRepository $roles, private OrganizationMembershipService $memberships)
    {
    }

    public function index(User $actor): array
    {
        return [
            'members' => $this->memberships->index($actor),
            'organization' => $this->tenants->currentOrganization($actor),
            'roles' => $this->roles->forScope('organization'),
        ];
    }

    public function invitationForm(User $actor): array
    {
        $organization = $this->tenants->assertCurrentAccess($actor);
        Gate::forUser($actor)->authorize('users.invite');

        return ['organization' => $organization, 'roles' => $this->roles->forScope('organization')];
    }
}
