<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\DTOs\Trees\TreeReadScope;
use App\Models\User;
use App\Services\Reports\ReportAccessService;
use App\Services\Tenancy\TenantContextService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Presenta elecciones explícitas; nunca activa otra institución ni consulta árboles. */
class MapScopeSelectionService
{
    private const TERRITORY_FILTERS = ['department_id', 'municipality_id', 'organization_id', 'inventory_id', 'tree_id'];

    private const INSPECTION_FILTERS = ['risk_priority', 'date_from', 'date_to', 'maturity_state_id',
        'diameter_class_number', 'height_class_number', 'physical_condition_ids', 'phytosanitary_condition_ids',
        'physical_condition_category_ids', 'phytosanitary_condition_category_ids', 'condition_mode',
        'cap_cm_min', 'cap_cm_max', 'dap_m_min', 'dap_m_max', 'total_height_m_min', 'total_height_m_max'];

    private const MAINTENANCE_FILTERS = ['maintenance_type_id', 'maintenance_status', 'maintenance_priority', 'has_pending_maintenance'];

    public function __construct(
        private MapAccessService $access,
        private ReportAccessService $reports,
        private TenantContext $context,
        private TenantContextService $tenants,
    ) {
    }

    /** Sin institución, la entrada normal presenta una elección sin cargar datos de otros ámbitos. */
    public function selectionPage(User $actor, array $input, string $route): ?array
    {
        $requested = $input['scope'] ?? null;
        if (is_string($requested) && trim($requested) === '') {
            $requested = null;
        }
        $scope = Validator::make(['scope' => $requested], [
            'scope' => ['nullable', 'string', Rule::in(['organization', 'department', 'national'])],
        ])->validate()['scope'] ?? 'organization';
        if ($scope !== 'organization' || $this->context->isActive()) {
            return null;
        }
        $scopes = $route === 'maps.index' ? $this->access->availableScopes($actor) : $this->reports->availableScopes($actor);
        $links = [];
        foreach ($scopes as $kind => $available) {
            if ($kind !== 'organization') {
                $links[] = ['label' => $this->label($kind), 'url' => route($route, ['scope' => $kind])];
            }
        }

        return ['selectionTitle' => match ($route) {
            'reports.index' => 'Reportes', 'analytics.index' => 'Diagramas y gráficas', default => 'Cartografía del arbolado',
        }, 'wideScopeLinks' => $links];
    }

    public function navigation(User $actor, TreeReadScope $current, array $filters): array
    {
        $organization = $this->context->isActive() ? $this->tenants->assertCurrentAccess($actor) : null;
        $options = [];
        foreach ($this->access->availableScopes($actor) as $kind => $scope) {
            $clear = self::TERRITORY_FILTERS;
            if (!$this->access->allows($actor, $scope, 'inspections.view')) {
                $clear = [...$clear, ...self::INSPECTION_FILTERS];
            }
            if (!$this->access->allows($actor, $scope, 'maintenances.view')) {
                $clear = [...$clear, ...self::MAINTENANCE_FILTERS];
            }
            $values = array_diff_key($filters, array_flip($clear));
            $values['scope'] = $kind;
            $options[] = ['kind' => $kind, 'label' => $this->label($kind), 'current' => $kind === $current->kind,
                'clear_fields' => $clear, 'url' => route('maps.index', $values)];
        }

        return ['organization_name' => $organization?->name, 'options' => $options,
            'description' => match ($current->kind) {
                'national' => 'Estás consultando todas las organizaciones de tu acceso nacional.',
                'department' => 'Estás consultando las organizaciones de tus departamentos autorizados.',
                default => 'Mostrando únicamente árboles de '.($organization?->name ?? 'la organización seleccionada').'.',
            }];
    }

    private function label(string $kind): string
    {
        return match ($kind) {
            'national' => 'Ver todas las organizaciones autorizadas',
            'department' => 'Ver organizaciones de mis departamentos',
            default => 'Solo la organización seleccionada',
        };
    }
}
