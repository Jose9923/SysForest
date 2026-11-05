<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\User;
use App\Repositories\Reports\ReportRepository;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Reports\ReportAccessService;
use App\Services\Reports\ReportFilterService;
use App\Services\Reports\ReportReadService;

/** Bandeja institucional y reporte comparten selección y última visita, sin recalcular resultados. */
class RiskTreeReadService
{
    public function __construct(
        private ForestryAccessService $access,
        private ReportReadService $reports,
        private ReportFilterService $filters,
        private ReportAccessService $reportAccess,
        private ReportRepository $groups,
    ) {
    }

    public function page(User $actor, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        foreach (['risk.view', 'trees.view', 'inspections.view'] as $permission) {
            $this->access->authorize($actor, $permission);
        }

        // El rol nacional/departamental no sustituye la institución elegida para esta bandeja.
        $input = array_replace($input, ['scope' => 'organization', 'report' => 'risk_trees']);
        $view = $this->reports->forRiskInbox($actor, $input);
        $selection = $this->filters->forRiskInbox($actor, $view['filters']);
        $counts = collect($this->groups->aggregate($selection, 'risk')['rows'])->pluck('trees', 'group');
        $riskSummary = [];
        foreach (['4' => 'Crítico', '3' => 'Alto', '2' => 'Moderado', '1' => 'Bajo',
            'pending' => 'Pendiente de confirmación', 'inconsistent' => 'Inconsistente', 'unevaluated' => 'Sin evaluar'] as $key => $label) {
            $riskSummary[] = ['key' => (string) $key, 'label' => $label, 'total' => (int) $counts->get($label, 0),
                'url' => route('risk.index', array_replace($view['filters'], ['risk_priority' => $key]), false)];
        }

        try {
            $this->reportAccess->authorize($actor, $selection, 'risk_trees');
            $canViewReport = true;
        } catch (AuthorizationException) {
            $canViewReport = false;
        }
        try {
            $this->reportAccess->authorize($actor, $selection, 'risk_trees', export: true);
            $canExport = true;
        } catch (AuthorizationException) {
            $canExport = false;
        }
        if (!$canExport) {
            $view['downloads'] = [];
        }
        $view['rows']->withPath(route('risk.index', absolute: false));
        // El paginador del reporte conserva report=risk_trees; sigue siendo un control fijo en /risk.

        return array_merge($view, ['organization' => $organization, 'riskSummary' => $riskSummary,
            'canManage' => $this->access->allows($actor, 'risk.manage'),
            'canCreateInspection' => $this->access->allows($actor, 'inspections.create'),
            'canChangeRisk' => $this->access->allows($actor, 'inspections.update'),
            'canViewTree' => $this->access->allows($actor, 'trees.view'),
            'canViewInspection' => $this->access->allows($actor, 'inspections.view'),
            'canViewReport' => $canViewReport, 'canExport' => $canExport,
            'reportUrl' => $canViewReport ? route('reports.index', ['report' => 'risk_trees'] + $view['filters'], false) : null,
            'aggregateReportUrl' => $canViewReport ? route('reports.index', ['report' => 'risk'] + $view['filters'], false) : null]);
    }
}
