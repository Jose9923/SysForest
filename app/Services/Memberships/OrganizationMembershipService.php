<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Models\Memberships\OrganizationMembership;
use App\Models\User;
use App\Repositories\Memberships\OrganizationMembershipRepository;
use App\Repositories\Auth\RoleAssignmentRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditSnapshotService;
use App\Services\Auth\PermissionService;
use App\Services\Auth\SecurityMailService;
use App\Services\Tenancy\TenantContextService;

class OrganizationMembershipService
{
    public function __construct(
        private TenantContext $context,
        private TenantContextService $tenants,
        private OrganizationMembershipRepository $memberships,
        private UserRepository $users,
        private RoleAssignmentRepository $assignments,
        private PermissionService $permissions,
        private SecurityEventRepository $events,
        private SecurityMailService $mail,
        private AuditSnapshotService $snapshots,
    ) {
    }

    public function index(User $actor): LengthAwarePaginator
    {
        $organization = $this->tenants->assertCurrentAccess($actor);
        $this->authorizePermission($actor, 'memberships.view', $organization->id);

        return $this->memberships->paginateForTenant($organization->id);
    }

    public function show(User $actor, string $id): OrganizationMembership
    {
        $this->tenants->assertCurrentAccess($actor);
        $membership = $this->memberships->findForTenant($this->context->organizationId(), $id);
        Gate::forUser($actor)->authorize('view', $membership);

        return $membership;
    }

    /** Agrega una cuenta activa como OFFICIAL; una membresía previa exige edición explícita. */
    public function add(User $actor, int $userId): OrganizationMembership
    {
        try {
            return DB::transaction(function () use ($actor, $userId): OrganizationMembership {
                $locked = $this->lockUsers($actor, $userId);
                $organization = $this->tenants->assertCurrentAccess($locked[(int) $actor->id], true);
                Gate::forUser($actor)->authorize('create', OrganizationMembership::class);
                $this->authorizePermission($actor, 'roles.assign-organization', $organization->id);
                $user = $locked[$userId];
                if (!$user->active) {
                    throw ValidationException::withMessages(['user_id' => 'La cuenta debe estar activa.']);
                }
                if ($this->memberships->findForUserInTenant($userId, $organization->id, true) !== null) {
                    throw ValidationException::withMessages(['user_id' => 'La membresía ya existe. Modifica su estado y rol desde la edición.']);
                }
                $membership = $this->memberships->create($userId, $organization->id, [
                    'active' => true, 'joined_at' => now(), 'left_at' => null,
                ]);
                $this->assignments->grantOrganization($membership, 'OFFICIAL');
                $this->events->recordChange(
                    'membership.created',
                    $actor,
                    $organization->id,
                    'organization_membership',
                    $membership->id,
                    [],
                    $this->snapshots->snapshot($membership) + ['role_name' => 'OFFICIAL'],
                    [
                        'membership_id' => $membership->id, 'target_user_id' => $user->id, 'role_name' => 'OFFICIAL',
                    ]
                );
                $this->mail->send('membership_activated', $user, ['organization' => $organization->name]);

                return $this->memberships->findForTenant($organization->id, $membership->id);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['user_id' => 'La membresía ya fue creada por otro proceso.']);
        }
    }

    public function setActive(User $actor, string $id, bool $active): OrganizationMembership
    {
        return $this->edit($actor, $id, ['active' => $active]);
    }

    /** @param array{active?: bool|int|string, role?: string} $input Cambios explícitos autorizados. */
    public function edit(User $actor, string $id, array $input): OrganizationMembership
    {
        $organization = $this->tenants->assertCurrentAccess($actor);
        $snapshot = $this->memberships->findForTenant($organization->id, $id);
        Gate::forUser($actor)->authorize('update', $snapshot);
        $attributes = Validator::make($input, [
            'active' => ['sometimes', 'required', 'boolean'],
            'role' => ['sometimes', 'required', 'in:ORGANIZATION_ADMIN,OFFICIAL'],
            'user_id' => ['prohibited'], 'organization_id' => ['prohibited'], 'id' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($actor, $id, $snapshot, $attributes): OrganizationMembership {
            $locked = $this->lockUsers($actor, (int) $snapshot->user_id);
            $organization = $this->tenants->assertCurrentAccess($locked[(int) $actor->id], true);
            $membership = $this->memberships->findForTenant($organization->id, $id, true);
            Gate::forUser($actor)->authorize('update', $membership);
            $user = $locked[(int) $membership->user_id];
            $newActive = array_key_exists('active', $attributes) ? (bool) $attributes['active'] : $membership->active;
            $stateChanged = $newActive !== $membership->active;
            if ($newActive && !$user->active) {
                throw ValidationException::withMessages(['active' => 'La cuenta global está desactivada; no se puede activar su membresía.']);
            }
            if ($stateChanged) {
                Gate::forUser($actor)->authorize($newActive ? 'activate' : 'deactivate', $membership);
            }
            $assignment = $this->assignments->organizationRole($membership);
            $before = $this->snapshots->snapshot($membership) + ['role_name' => $assignment?->role?->name];
            $roleChanged = isset($attributes['role']) && ($assignment === null || !$assignment->active
                || $assignment->revoked_at !== null || $assignment->role->name !== $attributes['role']);
            if ($roleChanged) {
                $this->authorizePermission($actor, 'roles.assign-organization', $organization->id);
                if (!$newActive) {
                    throw ValidationException::withMessages(['role' => 'Activa explícitamente la membresía antes de asignar un rol.']);
                }
            }
            if ($stateChanged) {
                $membership = $this->memberships->update($membership, [
                    'active' => $newActive, 'joined_at' => $membership->joined_at ?? now(),
                    'left_at' => $newActive ? null : now(),
                ]);
                $this->events->recordChange(
                    $newActive ? 'membership.activated' : 'membership.deactivated',
                    $actor,
                    $organization->id,
                    'organization_membership',
                    $membership->id,
                    $before,
                    $this->snapshots->snapshot($membership) + ['role_name' => $assignment?->role?->name],
                    [
                        'membership_id' => $membership->id, 'target_user_id' => $user->id,
                    ]
                );
                $this->mail->send($newActive ? 'membership_activated' : 'membership_deactivated', $user, ['organization' => $organization->name]);
            }
            if ($roleChanged) {
                $beforeRole = $this->snapshots->snapshot($membership) + ['role_name' => $assignment?->role?->name];
                $this->assignments->grantOrganization($membership, $attributes['role']);
                $this->events->recordChange(
                    'membership.role_changed',
                    $actor,
                    $organization->id,
                    'organization_membership',
                    $membership->id,
                    $beforeRole,
                    $this->snapshots->snapshot($membership) + ['role_name' => $attributes['role']],
                    [
                        'membership_id' => $membership->id, 'target_user_id' => $user->id, 'role_name' => $attributes['role'],
                    ]
                );
            }

            return $this->memberships->findForTenant($organization->id, $membership->id);
        }, 3);
    }

    private function authorizePermission(User $actor, string $permission, string $organizationId): void
    {
        if ($this->context->accessMode() === 'department_read'
            || !$this->permissions->allowsOrganization($actor, $permission, $organizationId)) {
            throw new AuthorizationException('No puedes administrar miembros en esta organización.');
        }
    }

    /** @return array<int, User> Cuentas bloqueadas en orden estable para evitar inversión de bloqueos. */
    private function lockUsers(User $actor, int $targetId): array
    {
        $ids = array_unique([(int) $actor->id, $targetId]);
        sort($ids, SORT_NUMERIC);
        $users = [];
        foreach ($ids as $id) {
            $users[$id] = $this->users->findForUpdate($id);
        }

        return $users;
    }
}
