<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReportSelectionDTO;
use App\DTOs\TreeReadScope;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ReportAccessService
{
    public function __construct(
        private MapAccessService $maps,
        private PermissionService $permissions,
        private TenantContextService $tenants,
        private TenantContext $context,
        private ReportCatalog $catalog,
    ) {
    }

    public function canView(User $actor): bool
    {
        try {
            $scope = $this->maps->resolve($actor);

            return $this->allows($actor, $scope, 'reports.view');
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** Reautoriza el alcance exacto recibido; nunca lo sustituye por otro rol más amplio del actor. */
    public function authorize(User $actor, ReportSelectionDTO $selection, string $report, bool $export = false): void
    {
        $definition = $this->catalog->get($report);
        if (!$definition['available']) {
            throw ValidationException::withMessages(['report' => $definition['notice']]);
        }
        if ($selection->isPublic()) {
            throw $this->denied();
        }
        $this->maps->authorize($actor, $selection->scope, 'trees.view');
        $required = ['reports.view', ...$definition['domains']];
        if ($export) {
            $required[] = 'reports.export';
        }
        if ($selection->filters->trees->hasInspectionFilters()) {
            $required[] = 'inspections.view';
        }
        if ($selection->filters->trees->hasMaintenanceFilters()) {
            $required[] = 'maintenances.view';
        }
        foreach (array_unique($required) as $permission) {
            if (!$this->allows($actor, $selection->scope, $permission)) {
                throw $this->denied();
            }
        }
    }

    /** Solo consulta de la bandeja institucional; exportar sigue exigiendo authorize(). */
    public function authorizeRiskInbox(User $actor, ReportSelectionDTO $selection): void
    {
        if ($selection->scope->kind !== 'organization') {
            throw $this->denied();
        }
        $this->maps->authorize($actor, $selection->scope, 'trees.view');
        $required = ['inspections.view', 'risk.view'];
        if ($selection->filters->trees->hasMaintenanceFilters()) {
            $required[] = 'maintenances.view';
        }
        foreach ($required as $permission) {
            if (!$this->allows($actor, $selection->scope, $permission)) {
                throw $this->denied();
            }
        }
    }

    private function allows(User $actor, TreeReadScope $scope, string $permission): bool
    {
        if ($scope->kind === 'national') {
            return $this->permissions->allowsNational($actor, $permission);
        }
        if ($scope->kind === 'department') {
            foreach ($scope->departmentIds as $departmentId) {
                if (!$this->permissions->allowsDepartment($actor, $permission, $departmentId)) {
                    return false;
                }
            }

            return $scope->departmentIds !== [];
        }
        if ($scope->kind !== 'organization') {
            return false;
        }
        $organization = $this->tenants->assertCurrentAccess($actor);
        if ($organization->id !== $scope->organizationId) {
            return false;
        }

        return $this->context->accessMode() === 'department_read'
            ? $this->permissions->allowsDepartment($actor, $permission, $organization->municipality->department_id)
            : $this->permissions->allowsOrganization($actor, $permission, $organization->id);
    }

    private function denied(): AuthorizationException
    {
        return new AuthorizationException('No tiene autorización para consultar o exportar este reporte en el alcance solicitado.');
    }
}
