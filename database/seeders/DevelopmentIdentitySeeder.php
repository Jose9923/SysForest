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

class DevelopmentIdentitySeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new LogicException('Las cuentas de desarrollo solo pueden prepararse en local o testing.');
        }

        // Toda la configuración se valida antes de sembrar catálogos o crear una cuenta.
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

    /** @return array<string, array{name: string, email: string, password: string}> */
    private function validatedAccounts(): array
    {
        $accounts = [];
        $seenEmails = [];

        foreach (['SUPER_ADMIN', 'DEPARTMENT_ADMIN', 'ORGANIZATION_ADMIN', 'OFFICIAL'] as $roleName) {
            $configuration = config('development.accounts.'.$roleName, []);
            $email = is_string($configuration['email'] ?? null) ? strtolower(trim($configuration['email'])) : null;
            $password = $configuration['password'] ?? null;
            $validator = Validator::make(
                ['email' => $email, 'password' => $password],
                [
                    'email' => ['required', 'string', 'email:rfc', 'max:254'],
                    'password' => ['required', 'string', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()],
                ],
            );

            if ($validator->fails()) {
                // No se adjuntan valores sensibles ni el objeto Validator a la excepción.
                throw new LogicException('Configura DEV_'.$roleName.'_EMAIL y DEV_'.$roleName.'_PASSWORD con un correo válido y una contraseña fuerte.');
            }

            if (in_array($email, $seenEmails, true)) {
                throw new LogicException('Las cuatro cuentas de desarrollo deben utilizar correos diferentes.');
            }

            $seenEmails[] = $email;
            $accounts[$roleName] = ['name' => $configuration['name'], 'email' => $email, 'password' => $password];
        }

        return $accounts;
    }
}
