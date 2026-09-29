<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use App\Repositories\InvitationRepository;
use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class InvitationService
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly RoleAssignmentRepository $assignments,
        private readonly OrganizationMembershipRepository $memberships,
        private readonly PermissionService $permissions,
        private readonly TenantContext $tenant,
        private readonly SecurityEventRepository $events,
        private readonly SecurityMailService $mail,
        private readonly AuditSnapshotService $snapshots,
    ) {
    }

    /** @param array{email: string, role: string} $input Datos explícitos de la invitación. */
    public function create(User $actor, array $input): Invitation
    {
        $organizationId = $this->tenant->organizationId();
        if ($this->tenant->actorId() !== (int) $actor->id) {
            throw new AuthorizationException('El contexto institucional no corresponde al usuario.');
        }
        $email = Str::lower(trim((string) ($input['email'] ?? '')));
        $values = Validator::make(['email' => $email, 'role' => $input['role'] ?? ''], [
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', 'in:ORGANIZATION_ADMIN,OFFICIAL'],
        ])->validate();

        return DB::transaction(function () use ($actor, $organizationId, $values): Invitation {
            $currentActor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->invitations->lockOrganization($organizationId);
            if (!$organization->active || $organization->trashed()
                || !$this->permissions->allowsOrganization($currentActor, 'users.invite', $organizationId)) {
                throw new AuthorizationException('No puedes invitar usuarios a esta organización.');
            }
            $role = $this->roles->findByName($values['role']);
            if ($role->scope !== 'organization') {
                throw ValidationException::withMessages(['role' => 'El rol debe pertenecer al ámbito de organización.']);
            }
            $existing = $this->users->findByEmail($values['email']);
            if ($existing !== null && $this->invitations->membershipForUser((int) $existing->id, $organizationId) !== null) {
                throw ValidationException::withMessages(['email' => 'Esta cuenta ya tiene una membresía. Administra su estado y rol desde membresías.']);
            }

            $token = bin2hex(random_bytes(32));
            foreach ($this->invitations->pendingForAddress($organizationId, $values['email']) as $previous) {
                $before = $this->snapshots->snapshot($previous);
                $this->invitations->revoke($previous);
                $this->events->recordChange(
                    'invitation.revoked',
                    $currentActor,
                    $organizationId,
                    'invitation',
                    $previous->id,
                    $before,
                    $this->snapshots->snapshot($previous),
                    ['invitation_id' => $previous->id, 'reason' => 'superseded']
                );
            }
            $invitation = $this->invitations->create([
                'organization_id' => $organizationId, 'email' => $values['email'],
                'role_id' => $role->id, 'scope' => 'organization', 'invited_by' => $currentActor->id,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48),
            ]);
            $this->events->recordChange(
                'invitation.created',
                $currentActor,
                $organizationId,
                'invitation',
                $invitation->id,
                [],
                $this->snapshots->snapshot($invitation),
                ['invitation_id' => $invitation->id, 'role_name' => $role->name]
            );
            $this->mail->send('invitation', $invitation->email, [
                'token' => $token, 'organization' => $organization->name, 'role' => $role->name, 'expires_minutes' => 2880,
            ]);

            return $invitation;
        }, 3);
    }

    /** Consulta sin consumir; el controlador debe reservar la aceptación para POST con CSRF. */
    public function preview(#[SensitiveParameter] string $token): Invitation
    {
        $invitation = $this->invitations->findByTokenHash($this->tokenHash($token));
        $this->assertUsable($invitation);

        return $invitation;
    }

    /** @param array{name?: string, password?: string, password_confirmation?: string} $input */
    public function accept(#[SensitiveParameter] string $token, #[SensitiveParameter] array $input, ?User $authenticatedActor = null): User
    {
        $snapshot = $this->preview($token);
        $existing = $this->users->findByEmail($snapshot->email);
        if ($authenticatedActor !== null && Str::lower($authenticatedActor->email) !== $snapshot->email) {
            throw ValidationException::withMessages(['token' => 'Inicia sesión con el correo invitado antes de aceptar.']);
        }
        if ($existing !== null && ($authenticatedActor === null || (int) $authenticatedActor->id !== (int) $existing->id)) {
            throw ValidationException::withMessages(['token' => 'Inicia sesión con el correo invitado antes de aceptar.']);
        }
        $newUserValues = $existing === null ? Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate() : [];

        try {
            return DB::transaction(function () use ($snapshot, $existing, $newUserValues): User {
                // Todas las cuentas se bloquean en orden estable antes de la invitación y la membresía.
                $ids = [(int) $snapshot->invited_by];
                if ($existing !== null) {
                    $ids[] = (int) $existing->id;
                }
                $ids = array_unique($ids);
                sort($ids, SORT_NUMERIC);
                $lockedUsers = [];
                foreach ($ids as $id) {
                    $lockedUsers[$id] = $this->users->findForUpdate($id);
                }
                $organization = $this->invitations->lockOrganization($snapshot->organization_id);
                $invitation = $this->invitations->lockById($snapshot->id);
                $this->assertUsable($invitation);
                $inviter = $lockedUsers[(int) $invitation->invited_by];
                if (!$organization->active || $organization->trashed()
                    || !$this->permissions->allowsOrganization($inviter, 'users.invite', $organization->id)) {
                    throw ValidationException::withMessages(['token' => 'La autorización de esta invitación ya no está vigente.']);
                }
                if (!in_array($invitation->role->name, ['ORGANIZATION_ADMIN', 'OFFICIAL'], true)
                    || $invitation->role->scope !== 'organization') {
                    throw ValidationException::withMessages(['token' => 'El rol de esta invitación no está permitido.']);
                }

                $user = $existing !== null ? $lockedUsers[(int) $existing->id] : null;
                if ($user !== null && (!$user->active || $user->email !== $invitation->email)) {
                    throw ValidationException::withMessages(['token' => 'La cuenta invitada no puede aceptar esta invitación.']);
                }
                if ($user !== null && $this->invitations->membershipForUser((int) $user->id, $organization->id) !== null) {
                    throw ValidationException::withMessages(['token' => 'La membresía ya existe. Su estado y rol deben administrarse explícitamente.']);
                }
                $beforeUser = $user === null ? [] : $this->snapshots->snapshot($user);
                $newIdentity = $user === null;
                if ($user === null) {
                    $user = $this->users->create([
                        'name' => $newUserValues['name'], 'email' => $invitation->email,
                        'password' => $newUserValues['password'], 'active' => true, 'email_verified_at' => now(),
                    ]);
                } elseif ($user->email_verified_at === null) {
                    $user = $this->users->update($user, ['email_verified_at' => now()]);
                }
                if ($newIdentity || $beforeUser !== $this->snapshots->snapshot($user)) {
                    $this->events->recordChange(
                        $newIdentity ? 'account.created' : 'email.verified',
                        $user,
                        null,
                        'user',
                        (string) $user->id,
                        $beforeUser,
                        $this->snapshots->snapshot($user),
                        ['target_user_id' => $user->id]
                    );
                }
                $membership = $this->memberships->upsert((int) $user->id, $organization->id, [
                    'active' => true, 'joined_at' => now(), 'left_at' => null,
                ]);
                $this->assignments->grantOrganization($membership, $invitation->role->name);
                $this->events->recordChange(
                    'membership.created',
                    $user,
                    $organization->id,
                    'organization_membership',
                    $membership->id,
                    [],
                    $this->snapshots->snapshot($membership) + ['role_name' => $invitation->role->name],
                    ['invitation_id' => $invitation->id, 'membership_id' => $membership->id, 'target_user_id' => $user->id, 'role_name' => $invitation->role->name]
                );
                $before = $this->snapshots->snapshot($invitation);
                $this->invitations->consume($invitation);
                $this->events->recordChange(
                    'invitation.accepted',
                    $user,
                    $organization->id,
                    'invitation',
                    $invitation->id,
                    $before,
                    $this->snapshots->snapshot($invitation),
                    ['invitation_id' => $invitation->id, 'membership_id' => $membership->id]
                );
                $this->mail->send($existing === null ? 'welcome' : 'membership_activated', $user, ['organization' => $organization->name]);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['token' => 'La cuenta o membresía cambió durante la operación. Inicia sesión y revisa la invitación.']);
        }
    }

    private function assertUsable(?Invitation $invitation): void
    {
        if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null
            || $invitation->expires_at->lessThanOrEqualTo(now()) || $invitation->organization === null
            || !$invitation->organization->active || $invitation->role === null) {
            throw ValidationException::withMessages(['token' => 'La invitación no es válida o ya venció.']);
        }
    }

    private function tokenHash(#[SensitiveParameter] string $token): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw ValidationException::withMessages(['token' => 'La invitación no es válida o ya venció.']);
        }

        return hash('sha256', $token);
    }
}
