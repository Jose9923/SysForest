<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\AuthSessionRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private UserRepository $users,
        private OrganizationMembershipRepository $memberships,
        private RoleAssignmentRepository $assignments,
        private DepartmentRepository $departments,
        private AuthSessionRepository $sessions,
        private SessionService $sessionService,
        private SecurityMailService $mail,
        private SecurityEventRepository $events,
        private RoleAssignmentService $roles,
    ) {
    }

    public function index(User $actor): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('users.view');

        return $this->users->paginate();
    }

    public function show(User $actor, int $id): array
    {
        Gate::forUser($actor)->authorize('users.view');
        $user = $this->users->find($id);

        return [
            'user' => $user,
            'nationalAssignments' => $this->assignments->nationalForUser($id),
            'departmentAssignments' => $this->assignments->departmentsForUser($id),
            'departments' => $this->departments->activeOptions(),
        ];
    }

    /** Desactivar la identidad global revoca sus sesiones; no altera sus membresías. */
    public function setActive(User $actor, int $id, bool $active): void
    {
        Gate::forUser($actor)->authorize('users.manage');
        DB::transaction(function () use ($actor, $id, $active): void {
            $locked = $this->lockUsers($actor, $id);
            Gate::forUser($locked[(int) $actor->id])->authorize('users.manage');
            $user = $locked[$id];
            if ($user->active === $active) {
                return;
            }
            $this->users->update($user, ['active' => $active]);
            if (!$active) {
                $this->sessionService->revokeAll($user, 'account_disabled');
            }
            $kind = $active ? 'account_activated' : 'account_deactivated';
            $this->mail->send($kind, $user);
            $this->events->record($active ? 'account.activated' : 'account.deactivated', $actor, null, ['target_user_id' => $id]);
        }, 3);
    }

    public function assignRole(User $actor, int $id, array $input): void
    {
        Gate::forUser($actor)->authorize($input['scope'] === 'national' ? 'roles.assign-national' : 'roles.assign-department');
        DB::transaction(function () use ($actor, $id, $input): void {
            $locked = $this->lockUsers($actor, $id);
            $actor = $locked[(int) $actor->id];
            $user = $locked[$id];
            if ($input['scope'] === 'national' && $input['role'] === 'SUPER_ADMIN') {
                $this->roles->assignNational($actor, $user, $input['role']);
            } elseif ($input['scope'] === 'department' && $input['role'] === 'DEPARTMENT_ADMIN') {
                $this->roles->assignDepartment($actor, $user, $input['department_id'], $input['role']);
            } else {
                throw ValidationException::withMessages(['role' => 'El rol no corresponde al ámbito solicitado.']);
            }
            $this->events->record('role.assigned', $actor, null, ['target_user_id' => $id, 'role_name' => $input['role'], 'department_id' => $input['department_id'] ?? null]);
        });
    }

    /** Bloqueo estable compartido con autenticación, invitaciones y cambios de membresía. */
    private function lockUsers(User $actor, int $targetId): array
    {
        $ids = array_unique([(int) $actor->id, $targetId]);
        sort($ids, SORT_NUMERIC);
        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = $this->users->findForUpdate($id);
        }

        return $locked;
    }

    public function profile(User $actor, string $currentSessionId): array
    {
        return [
            'user' => $this->users->find((int) $actor->id),
            'memberships' => $this->memberships->forUser((int) $actor->id),
            'sessions' => $this->sessions->unrevokedForUser((int) $actor->id),
            'nationalAssignments' => $this->assignments->nationalForUser((int) $actor->id),
            'departmentAssignments' => $this->assignments->departmentsForUser((int) $actor->id),
            'currentSessionId' => $currentSessionId,
        ];
    }

    public function updateProfile(User $actor, array $input): void
    {
        $this->users->update($actor, ['name' => $input['name']]);
    }

    public function revokeOwnSession(User $actor, string $id): void
    {
        $session = $this->sessions->findForUser($id, (int) $actor->id);
        abort_if($session === null, 404);
        $this->sessionService->revoke($session, 'user_revoked');
    }
}
