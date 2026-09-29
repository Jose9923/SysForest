<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\OrganizationMembershipRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\UserRepository;
use App\Services\PilotSeedService;
use App\Services\DevelopmentPasswordService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        if (!app()->runningInConsole() || !app()->environment(['local', 'testing'])) {
            throw new LogicException('Las cuentas de desarrollo solo pueden prepararse en local o testing.');
        }

        // Los correos del piloto se validan antes de sembrar dependencias.
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
                    $user = $users->create([
                        'name' => $account['name'],
                        'email' => $account['email'],
                        'password' => DevelopmentPasswordService::DEFAULT_PASSWORD,
                        'active' => true,
                        'email_verified_at' => now(),
                    ]);
                } else {
                    // El restablecimiento de claves se coordina al terminar, conservando estado y verificación.
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

            $this->call(DevelopmentPasswordSeeder::class);
        });
    }

    /**
     * Valida las identidades del piloto; la clave predeterminada pertenece al servicio local.
     *
     * @return array<string, array{name: string, email: string}>
     * @throws LogicException Si los correos no son válidos o se repiten.
     */
    private function validatedAccounts(): array
    {
        $accounts = [];
        $seenEmails = [];
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
            ];
        }

        return $accounts;
    }

}
