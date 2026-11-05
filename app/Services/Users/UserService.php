<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Repositories\Auth\AuthSessionRepository;
use App\Repositories\Territories\DepartmentRepository;
use App\Repositories\Memberships\OrganizationMembershipRepository;
use App\Repositories\Auth\RoleAssignmentRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Services\Audit\AuditSnapshotService;
use App\Services\Auth\RoleAssignmentService;
use App\Services\Auth\SecurityMailService;
use App\Services\Auth\SessionService;

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
        private AuditSnapshotService $snapshots,
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
            $before = $this->snapshots->snapshot($user);
            $this->users->update($user, ['active' => $active]);
            if (!$active) {
                $this->sessionService->revokeAll($user, 'account_disabled', $actor);
            }
            $kind = $active ? 'account_activated' : 'account_deactivated';
            $this->mail->send($kind, $user);
            $this->events->recordChange(
                $active ? 'account.activated' : 'account.deactivated',
                $actor,
                null,
                'user',
                (string) $id,
                $before,
                $this->snapshots->snapshot($user),
                ['target_user_id' => $id]
            );
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
        $values = \Illuminate\Support\Facades\Validator::make($input, ['name' => ['required', 'string', 'max:150']])->validate();
        DB::transaction(function () use ($actor, $values): void {
            $user = $this->users->findForUpdate((int) $actor->id);
            if (!$user->active) {
                throw new \Illuminate\Auth\Access\AuthorizationException('La cuenta debe estar activa.');
            }
            $before = $this->snapshots->snapshot($user);
            $user = $this->users->update($user, ['name' => $values['name']]);
            $this->events->recordChange(
                'account.profile_updated',
                $user,
                null,
                'user',
                (string) $user->id,
                $before,
                $this->snapshots->snapshot($user),
                ['target_user_id' => $user->id]
            );
        }, 3);
    }

    public function revokeOwnSession(User $actor, string $id): void
    {
        $session = $this->sessions->findForUser($id, (int) $actor->id);
        abort_if($session === null, 404);
        $this->sessionService->revoke($session, 'user_revoked');
    }
}
