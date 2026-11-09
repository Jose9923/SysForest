<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auditing\AuditSnapshot;
use App\Repositories\Auth\BaseSeedRepository;
use App\Repositories\Auth\RoleAssignmentRepository;
use App\Repositories\Auth\RoleRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use LogicException;

/** Alta inicial explícita por consola; repetir conserva credenciales y asignaciones existentes. */
class BaseAdministratorService
{
    public function __construct(
        private readonly BaseSeedRepository $bootstrap,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly RoleAssignmentRepository $assignments,
        private readonly SecurityEventRepository $events,
        private readonly AuditSnapshot $snapshots,
    ) {
    }

    /** @return array{email: string, created: bool} */
    public function seed(): array
    {
        if (!app()->runningInConsole() || !app()->environment(['local', 'testing', 'production'])) {
            throw new LogicException('La preparación base solo está disponible desde consola en un entorno autorizado.');
        }

        $email = Str::lower(trim((string) config('base_seed.admin_email')));
        $name = trim((string) config('base_seed.admin_name'));
        Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email:rfc', 'max:254'],
            'name' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($email, $name): array {
            $this->bootstrap->lock();
            $role = $this->roles->findByName('SUPER_ADMIN');
            if ($role->scope !== 'national' || !$role->permissions->pluck('code')->contains('users.manage')
                || !$role->permissions->pluck('code')->contains('organizations.create')) {
                throw new LogicException('Ejecuta AuthorizationSeeder antes de preparar el administrador base.');
            }

            $existing = $this->users->findByEmail($email);
            if ($existing !== null) {
                $existing = $this->users->findForUpdate((int) $existing->id);
                $assignment = $this->assignments->nationalAssignment((int) $existing->id, 'SUPER_ADMIN', true);
                if (Str::lower(trim($existing->email)) !== $email || !$existing->active
                    || $existing->email_verified_at === null || $assignment === null
                    || !$assignment->active || $assignment->revoked_at !== null
                    || Hash::check(DevelopmentPasswordService::DEFAULT_PASSWORD, $existing->password)) {
                    throw new LogicException('El correo del administrador base ya existe sin acceso administrativo vigente; no se modifica ni se promueve automáticamente.');
                }

                return ['email' => $email, 'created' => false];
            }

            $password = (string) config('base_seed.admin_password');
            try {
                // Excepción inicial solicitada: mínimo 10, sin cambiar la política web de 12.
                Validator::make(['password' => $password], [
                    'password' => ['required', 'string', 'max:128', Password::min(10)->mixedCase()->numbers()->symbols()],
                ], [
                    'password.required' => 'Configura SYSFOREST_BASE_ADMIN_PASSWORD en el entorno privado antes de crear el administrador.',
                    'password.min' => 'La clave inicial debe tener al menos 10 caracteres.',
                    'password.max' => 'La clave inicial no puede superar 128 caracteres.',
                ])->validate();
                $user = $this->users->create([
                    'name' => $name, 'email' => $email, 'password' => $password,
                    'active' => true, 'email_verified_at' => now(),
                ]);
            } finally {
                unset($password);
            }

            $this->events->record(
                'user.production_bootstrap',
                null,
                null,
                ['reason' => 'explicit_base_seed'],
                'user',
                (string) $user->id,
                [],
                $this->snapshots->snapshot($user)
            );
            $assignment = $this->assignments->grantNational((int) $user->id);
            $this->events->record(
                'role.production_bootstrap',
                null,
                null,
                ['reason' => 'explicit_base_seed', 'role_name' => 'SUPER_ADMIN'],
                'national_role_assignment',
                $assignment->id,
                [],
                $this->snapshots->snapshot($assignment)
            );

            return ['email' => $email, 'created' => true];
        }, 3);
    }
}
