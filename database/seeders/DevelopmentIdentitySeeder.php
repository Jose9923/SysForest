<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\UserRepository;
use App\Services\PilotSeedService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use LogicException;

/** Aprovisiona perfiles del piloto local sin conservar sus contraseñas en archivos de configuración. */
class DevelopmentIdentitySeeder extends Seeder
{
    /** @var array<string, array{name: string, email: string}> */
    private const ACCOUNTS = [
        'SUPER_ADMIN' => [
            'name' => 'Superadministrador de desarrollo',
            'email' => 'superadmin@sysforest.test',
        ],
        'DEPARTMENT_ADMIN' => [
            'name' => 'Administrador departamental de desarrollo',
            'email' => 'departamento.meta@sysforest.test',
        ],
        'ORGANIZATION_ADMIN' => [
            'name' => 'Administrador INEM de desarrollo',
            'email' => 'admin.inem@sysforest.test',
        ],
        'OFFICIAL' => [
            'name' => 'Funcionario INEM de desarrollo',
            'email' => 'funcionario.inem@sysforest.test',
        ],
    ];

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new LogicException('Las cuentas de desarrollo solo pueden prepararse en local o testing.');
        }

        // Las preguntas y su validación terminan antes de abrir la transacción o sembrar dependencias.
        $accounts = $this->validatedAccounts();

        DB::transaction(function () use ($accounts): void {
            $this->call(AuthorizationSeeder::class);
            $organization = app(PilotSeedService::class)->seedOrganization()->load('municipality.department.country');
            $users = app(UserRepository::class);
            $memberships = app(OrganizationMembershipRepository::class);
            $assignments = app(RoleAssignmentRepository::class);

            foreach ($accounts as $roleName => $account) {
                $user = $users->findByEmail($account['email']);

                if ($user === null) {
                    if ($account['password'] === null) {
                        throw new LogicException('Una cuenta cambió durante la preparación. Ejecuta nuevamente el seeder.');
                    }

                    $user = $users->create([
                        'name' => $account['name'],
                        'email' => $account['email'],
                        'password' => $account['password'],
                        'active' => true,
                        'email_verified_at' => now(),
                    ]);
                } else {
                    // Una segunda ejecución no cambia contraseña, estado ni verificación existentes.
                    $user = $users->findForUpdate((int) $user->id);
                }

                if ($roleName === 'SUPER_ADMIN') {
                    $assignments->grantNational((int) $user->id);
                    continue;
                }

                if ($roleName === 'DEPARTMENT_ADMIN') {
                    $assignments->grantDepartment((int) $user->id, $organization->municipality->department_id);
                    continue;
                }

                $membership = $memberships->forUser((int) $user->id)->firstWhere('organization_id', $organization->id)
                    ?? $memberships->upsert(
                        (int) $user->id,
                        $organization->id,
                        ['active' => true, 'joined_at' => now(), 'left_at' => null],
                    );

                $assignments->grantOrganization($membership, $roleName);
            }
        });
    }

    /**
     * Conserva la identidad del piloto y pide secretos únicamente para cuentas todavía inexistentes.
     *
     * @return array<string, array{name: string, email: string, password: string|null}>
     * @throws LogicException Si los correos no son válidos o no es posible recibir una contraseña segura.
     */
    private function validatedAccounts(): array
    {
        $accounts = [];
        $seenEmails = [];
        $users = app(UserRepository::class);

        foreach (self::ACCOUNTS as $roleName => $configuration) {
            $email = strtolower(trim($configuration['email']));
            $validator = Validator::make(
                ['email' => $email],
                ['email' => ['required', 'string', 'email:rfc', 'max:254']],
            );

            if ($validator->fails() || in_array($email, $seenEmails, true)) {
                throw new LogicException('Los correos definidos por el seeder deben ser válidos y diferentes.');
            }

            $seenEmails[] = $email;
            $accounts[$roleName] = [
                'name' => $configuration['name'],
                'email' => $email,
                'password' => $users->findByEmail($email) === null ? $this->askPassword($email) : null,
            ];
        }

        return $accounts;
    }

    /**
     * Recibe el secreto por una terminal interactiva; nunca admite el fallback que muestra caracteres.
     * La contraseña solo vive en memoria hasta que User la almacena mediante su cast de hash.
     */
    private function askPassword(string $email): string
    {
        if ($this->command === null || $this->command->option('no-interaction') || !defined('STDIN') || !stream_isatty(STDIN)) {
            throw new LogicException('Faltan cuentas locales. Ejecuta docker compose exec app php artisan db:seed --class=DevelopmentIdentitySeeder desde una terminal interactiva.');
        }

        $password = $this->command->secret('Contraseña inicial para '.$email, false);
        $confirmation = $this->command->secret('Confirma la contraseña para '.$email, false);
        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()]],
        );

        if ($validator->fails()) {
            // Se informa la política sin adjuntar los datos del Validator ni valores sensibles.
            throw new LogicException('Las contraseñas deben coincidir, tener entre 12 y 128 caracteres e incluir mayúsculas, minúsculas, números y símbolos. No se crearon cuentas.');
        }

        return $password;
    }
}
