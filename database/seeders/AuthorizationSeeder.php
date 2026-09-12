<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = app(PermissionRepository::class);
        $roles = app(RoleRepository::class);

        DB::transaction(function () use ($permissions, $roles): void {
            $catalog = [
                'organizations.view' => 'Consultar organizaciones dentro del alcance autorizado.',
                'organizations.create' => 'Crear una organización desde la administración nacional.',
                'organizations.manage' => 'Modificar la configuración institucional autorizada.',
                'memberships.view' => 'Consultar la administración de miembros de una organización.',
                'memberships.create' => 'Agregar miembros mediante un flujo autorizado.',
                'memberships.update' => 'Actualizar miembros de la organización autorizada.',
                'memberships.activate' => 'Activar una membresía institucional.',
                'memberships.deactivate' => 'Desactivar una membresía sin desactivar la cuenta global.',
                'users.view' => 'Consultar usuarios dentro del alcance autorizado.',
                'users.manage' => 'Administrar cuentas globales.',
                'users.invite' => 'Invitar usuarios a una organización autorizada.',
                'territories.view' => 'Consultar la jerarquía territorial del alcance autorizado.',
                'territories.manage' => 'Administrar el catálogo territorial nacional.',
                'roles.assign-national' => 'Conceder roles nacionales.',
                'roles.assign-department' => 'Conceder roles para un departamento explícito.',
                'roles.assign-organization' => 'Conceder roles organizacionales a una membresía.',
                'sessions.view' => 'Consultar sesiones desde la administración global.',
                'sessions.revoke' => 'Revocar sesiones desde la administración global.',
            ];
            $ids = [];

            foreach ($catalog as $code => $description) {
                $ids[$code] = $permissions->upsert($code, $description)->id;
            }

            // Catálogo explícito: ningún rol obtiene automáticamente permisos añadidos en otras fases.
            $matrix = [
                'SUPER_ADMIN' => ['national', array_keys($catalog)],
                'DEPARTMENT_ADMIN' => ['department', ['organizations.view', 'territories.view']],
                'ORGANIZATION_ADMIN' => ['organization', [
                    'organizations.view', 'organizations.manage', 'memberships.view', 'memberships.create',
                    'memberships.update', 'memberships.activate', 'memberships.deactivate', 'users.view',
                    'users.invite', 'territories.view', 'roles.assign-organization',
                ]],
                'OFFICIAL' => ['organization', ['organizations.view', 'territories.view']],
            ];

            foreach ($matrix as $name => [$scope, $codes]) {
                $role = $roles->upsert($name, $scope);
                $roles->syncPermissions($role, array_map(fn (string $code): string => $ids[$code], $codes));
            }
        });
    }
}
