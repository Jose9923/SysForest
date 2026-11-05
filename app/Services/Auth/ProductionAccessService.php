<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auditing\AuditSnapshot;
use App\Models\User;
use App\Repositories\Auth\AccountActionTokenRepository;
use App\Repositories\Auth\AuthSessionRepository;
use App\Repositories\Auth\RoleAssignmentRepository;
use App\Repositories\Auth\RoleRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/** Preparación explícita del acceso tras restaurar un piloto; nunca se ejecuta desde un seeder o HTTP. */
class ProductionAccessService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly RoleAssignmentRepository $assignments,
        private readonly AccountActionTokenRepository $tokens,
        private readonly AuthSessionRepository $authSessions,
        private readonly SessionService $sessions,
        private readonly PasswordService $passwords,
        private readonly SecurityEventRepository $events,
        private readonly AuditSnapshot $snapshots,
    ) {
    }

    /** Consulta sin escrituras, enlaces nuevos ni correos. */
    public function inspect(?string $email = null): array
    {
        $this->assertEnvironment();
        $email = $email === null ? null : $this->email($email);
        $users = $this->users->allForSecurityReview();
        $affected = $users->filter(fn (User $user): bool => $this->usesDevelopmentPassword($user));
        $administrator = $email === null ? null : $users->first(
            fn (User $user): bool => Str::lower($user->email) === $email
        );

        return [
            'status' => 'preview',
            'users' => $users->count(),
            'development_passwords' => $affected->count(),
            'unrevoked_sessions' => $this->authSessions->countUnrevoked(),
            'pending_password_reset_links' => $this->tokens->countPending('password_reset'),
            'administrator' => $email === null ? 'not_selected' : $this->administratorStatus($administrator),
        ];
    }

    /**
     * El operador con acceso a consola confirma la titularidad del correo del administrador nuevo.
     * Todas las modificaciones y su auditoría son atómicas; repetir no altera claves personalizadas.
     */
    public function prepare(string $email, string $name, #[SensitiveParameter] ?string $password, bool $revokeAllSessions = false): array
    {
        $this->assertEnvironment();
        $email = $this->email($email);

        return DB::transaction(function () use ($email, $name, $password, $revokeAllSessions): array {
            $role = $this->roles->findByName('SUPER_ADMIN');
            if ($role->scope !== 'national'
                || !$role->permissions->pluck('code')->contains('organizations.view')
                || !$role->permissions->pluck('code')->contains('users.manage')) {
                throw new LogicException('La autorización SUPER_ADMIN existente está incompleta; revisa la restauración.');
            }
            // Orden estable usuario → asignación/sesiones, compartido con los cambios de acceso ordinarios.
            $users = $this->users->allForUpdate();
            $administrator = $users->first(fn (User $user): bool => Str::lower($user->email) === $email);
            $status = $this->administratorStatus($administrator, lock: true);
            if ($status === 'blocked') {
                throw ValidationException::withMessages([
                    'admin_email' => 'El correo ya pertenece a una cuenta que este comando no puede modificar. Elige un correo nuevo o utiliza la administración habitual.',
                ]);
            }
            $created = $administrator === null;
            if ($created) {
                Validator::make(['name' => trim($name)], ['name' => ['required', 'string', 'max:255']])->validate();
                $this->passwords->validatePassword($password ?? '');
                $administrator = $this->users->create([
                    'name' => trim($name),
                    'email' => $email,
                    'password' => $password,
                    'active' => true,
                    'email_verified_at' => now(),
                ]);
                $this->events->record(
                    'user.production_bootstrap',
                    null,
                    null,
                    ['target_user_id' => (int) $administrator->id, 'reason' => 'operator_confirmed_email'],
                    'user',
                    (string) $administrator->id,
                    [],
                    $this->snapshots->snapshot($administrator)
                );
                $assignment = $this->assignments->grantNational((int) $administrator->id);
                $this->events->record(
                    'role.production_bootstrap',
                    null,
                    null,
                    ['target_user_id' => (int) $administrator->id, 'role_name' => 'SUPER_ADMIN'],
                    'national_role_assignment',
                    $assignment->id,
                    [],
                    $this->snapshots->snapshot($assignment)
                );
            }

            $changed = 0;
            $revoked = 0;
            $invalidated = 0;
            foreach ($users as $user) {
                $developmentPassword = $this->usesDevelopmentPassword($user);
                if (!$developmentPassword && !$revokeAllSessions) {
                    continue;
                }
                if ($developmentPassword) {
                    // Se descarta el valor aleatorio: el acceso futuro requiere recuperación o gestión autorizada.
                    $user = $this->users->update($user, [
                        'password' => bin2hex(random_bytes(48)),
                        'remember_token' => Str::random(60),
                    ]);
                    $this->events->record(
                        'password.development_retired',
                        null,
                        null,
                        ['target_user_id' => (int) $user->id, 'reason' => 'production_access_preparation'],
                        'user',
                        (string) $user->id,
                        [],
                        []
                    );
                    $changed++;
                }
                $consumed = $this->tokens->consumePending($user, 'password_reset');
                $invalidated += $consumed;
                $revoked += $this->sessions->revokeAll(
                    $user,
                    $revokeAllSessions ? 'production_migration' : 'production_development_password_retired',
                    $administrator
                );
                if ($consumed > 0) {
                    $this->events->record(
                        'password.reset_links_retired',
                        null,
                        null,
                        ['target_user_id' => (int) $user->id, 'reason' => 'production_access_preparation'],
                        'user',
                        (string) $user->id,
                        [],
                        []
                    );
                }
            }

            return [
                'status' => 'prepared',
                'development_passwords_retired' => $changed,
                'sessions_revoked' => $revoked,
                'password_reset_links_invalidated' => $invalidated,
                'administrator_created' => $created,
                'administrator_id' => (int) $administrator->id,
                'existing_users_preserved' => $users->count(),
            ];
        }, 3);
    }

    private function assertEnvironment(): void
    {
        if (!app()->runningInConsole() || !app()->environment(['production', 'testing'])) {
            throw new LogicException('La preparación de producción solo admite consola en production o testing.');
        }
    }

    private function email(string $email): string
    {
        $email = Str::lower(trim($email));
        Validator::make(['email' => $email], ['email' => ['required', 'string', 'email:rfc', 'max:254']])->validate();
        $domain = substr($email, strrpos($email, '@') + 1);
        if (!str_contains($domain, '.')
            || in_array($domain, ['example.com', 'example.net', 'example.org'], true)
            || Str::endsWith($domain, ['.test', '.invalid', '.example', '.localhost', '.example.com', '.example.net', '.example.org'])) {
            throw ValidationException::withMessages(['email' => 'Indica un correo real bajo control del administrador, no una dirección de ejemplo.']);
        }

        return $email;
    }

    private function usesDevelopmentPassword(User $user): bool
    {
        return Hash::check(DevelopmentPasswordService::DEFAULT_PASSWORD, $user->password);
    }

    private function administratorStatus(?User $administrator, bool $lock = false): string
    {
        if ($administrator === null) {
            return 'new';
        }
        $assignment = $this->assignments->nationalAssignment((int) $administrator->id, 'SUPER_ADMIN', $lock);

        return $administrator->active && $administrator->email_verified_at !== null
            && !$this->usesDevelopmentPassword($administrator)
            && $assignment !== null && $assignment->active && $assignment->revoked_at === null
            ? 'existing_secure' : 'blocked';
    }
}
