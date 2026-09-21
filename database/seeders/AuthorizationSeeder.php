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
                'catalogs.view' => 'Consultar los catálogos forestales nacionales desde un rol vigente.',
                'catalogs.manage' => 'Administrar exclusivamente los catálogos forestales nacionales.',
                'inventories.view' => 'Consultar inventarios en el alcance autorizado.',
                'inventories.create' => 'Crear inventarios de la organización seleccionada.',
                'inventories.update' => 'Administrar inventarios de la organización seleccionada.',
                'trees.view' => 'Consultar individuos forestales en el alcance autorizado.',
                'trees.create' => 'Registrar individuos forestales en la organización seleccionada.',
                'trees.update' => 'Editar individuos forestales de la organización seleccionada.',
                'trees.void' => 'Anular registros forestales creados por error.',
                'trees.restore' => 'Restaurar registros forestales anulados.',
                'inspections.view' => 'Consultar inspecciones en el alcance institucional autorizado.',
                'inspections.create' => 'Registrar inspecciones históricas de la organización seleccionada.',
                'inspections.update' => 'Corregir inspecciones institucionales con auditoría y control de versión.',
                'evidences.view' => 'Consultar fotografías privadas dentro del alcance autorizado.',
                'evidences.create' => 'Adjuntar evidencias a individuos e inspecciones de la organización.',
                'evidences.update' => 'Corregir clasificación y descripción de evidencias institucionales.',
                'evidences.delete' => 'Anular evidencias cargadas por error conservando el archivo.',
                'evidences.restore' => 'Restaurar evidencias anuladas cuyo archivo permanece disponible.',
                'maintenances.view' => 'Consultar órdenes e historial de mantenimiento dentro del alcance autorizado.',
                'maintenances.create' => 'Crear manualmente órdenes de mantenimiento institucionales.',
                'maintenances.update' => 'Corregir y asignar órdenes abiertas de la organización.',
                'maintenances.transition' => 'Programar, iniciar, ejecutar o cancelar órdenes autorizadas.',
                'maintenances.verify' => 'Verificar administrativamente el resultado de una intervención ejecutada.',
                'maintenances.void' => 'Anular órdenes creadas por error conservando su historial.',
                'maintenances.restore' => 'Restaurar órdenes institucionales anuladas por error.',
            ];
            $ids = [];

            foreach ($catalog as $code => $description) {
                $ids[$code] = $permissions->upsert($code, $description)->id;
            }

            // Catálogo explícito: ningún rol obtiene automáticamente permisos añadidos en otras fases.
            $matrix = [
                'SUPER_ADMIN' => ['national', array_keys($catalog)],
                'DEPARTMENT_ADMIN' => ['department', ['organizations.view', 'territories.view', 'catalogs.view', 'inventories.view', 'trees.view', 'inspections.view', 'evidences.view', 'maintenances.view']],
                'ORGANIZATION_ADMIN' => ['organization', [
                    'organizations.view', 'organizations.manage', 'memberships.view', 'memberships.create',
                    'memberships.update', 'memberships.activate', 'memberships.deactivate', 'users.view',
                    'users.invite', 'territories.view', 'roles.assign-organization', 'catalogs.view',
                    'inventories.view', 'inventories.create', 'inventories.update',
                    'trees.view', 'trees.create', 'trees.update', 'trees.void', 'trees.restore',
                    'inspections.view', 'inspections.create', 'inspections.update',
                    'evidences.view', 'evidences.create', 'evidences.update', 'evidences.delete', 'evidences.restore',
                    'maintenances.view', 'maintenances.create', 'maintenances.update', 'maintenances.transition', 'maintenances.verify', 'maintenances.void', 'maintenances.restore',
                ]],
                'OFFICIAL' => ['organization', ['organizations.view', 'territories.view', 'catalogs.view', 'inventories.view', 'trees.view', 'trees.create', 'trees.update', 'inspections.view', 'inspections.create', 'inspections.update', 'evidences.view', 'evidences.create', 'evidences.update', 'evidences.delete', 'maintenances.view', 'maintenances.create', 'maintenances.update', 'maintenances.transition']],
            ];

            foreach ($matrix as $name => [$scope, $codes]) {
                $role = $roles->upsert($name, $scope);
                $roles->syncPermissions($role, array_map(fn (string $code): string => $ids[$code], $codes));
            }
        });
    }
}
