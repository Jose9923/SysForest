<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Support\Mobile\MobileIdentifiers as Ids;
use App\Models\User;
use App\Repositories\Reports\ExportReadRepository;
use App\Repositories\Inventories\InventoryRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Reports\ReportFilterService;

/** Bandeja móvil institucional con la selección determinista compartida por la web. */
class MobileRiskInboxService
{
    public function __construct(
        private ForestryAccessService $access,
        private InventoryRepository $inventories,
        private ReportFilterService $filters,
        private ExportReadRepository $rows,
    ) {
    }

    public function index(User $actor, array $input): array
    {
        $rules = ['inventory_id' => ['required', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'q' => ['nullable', 'string', 'max:200'],
            'risk_priority' => ['nullable', Rule::in(['1', '2', '3', '4', 'pending', 'inconsistent', 'unevaluated'])]];
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['filters' => 'La bandeja solo admite los filtros de inventario, búsqueda, prioridad y paginación.']);
        }
        $data = Ids::input(Validator::make($input, $rules)->validate());
        $organization = $this->access->organization($actor);
        Gate::forUser($actor)->authorize('view', $this->inventories->findForTenant($organization->id, $data['inventory_id']));
        $selection = $this->filters->forRiskInbox($actor, array_intersect_key($data, array_flip(['inventory_id', 'q', 'risk_priority'])));
        $page = $this->rows->paginate($selection, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 100), riskOnly: true);
        $ids = app(\App\Repositories\Identifiers\IdentifierRepository::class);
        $ids->prime('trees', $page->getCollection()->pluck('_source.tree_id')->filter()->all());
        $ids->prime('tree_inspections', $page->getCollection()->pluck('_source.inspection_id')->filter()->all());
        $canChange = $this->access->allows($actor, 'inspections.update');
        $rows = $page->getCollection()->map(fn (array $row): array => [
            'id' => Ids::external('trees', $row['_source']['tree_id']), 'tree_id' => Ids::external('trees', $row['_source']['tree_id']), 'tree_code' => $row['code'],
            'common_names' => $row['common_names'], 'scientific_name' => $row['scientific_name'],
            'location_description' => $row['location_description'], 'tree_status_label' => $row['current_status_label'],
            'inspection_id' => Ids::external('tree_inspections', $row['_source']['inspection_id']), 'inspection_version' => $row['_source']['inspection_version'],
            'inspection_date' => $row['latest_inspection_date'], 'risk_group' => $row['_source']['risk_group'],
            'risk_priority_label' => $row['risk_priority'], 'risk_suggestion_label' => $row['risk_suggestion'],
            'risk_confirmed_at' => $row['risk_confirmed_at'],
            'can_change_risk' => $canChange && $row['_source']['inspection_id'] !== null,
        ])->all();

        return ['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(), 'inventory_id' => Ids::external('inventories', $data['inventory_id']),
            'organization_id' => Ids::id($organization), 'generated_at' => now()->toIso8601String()]];
    }
}
