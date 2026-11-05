<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\MaintenanceRepository;
use App\Repositories\MobileTreeSyncRepository;
use App\Repositories\TreeRepository;
use App\Validation\MaintenanceInput;
use App\Validation\MobileMaintenanceInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Consulta privada para trabajo de campo; no concede operaciones de mantenimiento. */
class MobileMaintenanceService
{
    public function __construct(
        private ForestryAccessService $access,
        private InventoryRepository $inventories,
        private TreeRepository $trees,
        private MobileTreeSyncRepository $participations,
        private MaintenanceRepository $maintenances,
    ) {
    }

    public function index(User $actor, string $inventoryId, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        $inventory = $this->inventories->findForTenant($organization->id, $inventoryId);
        Gate::forUser($actor)->authorize('view', $inventory);
        Gate::forUser($actor)->authorize('viewAny', Tree::class);
        Gate::forUser($actor)->authorize('viewAny', Maintenance::class);
        if (array_diff(array_keys($input), array_keys(MobileMaintenanceInput::rules())) !== []) {
            throw ValidationException::withMessages(['filters' => 'La consulta contiene filtros no permitidos.']);
        }
        $filters = Validator::make(MaintenanceInput::normalize($input), MobileMaintenanceInput::rules(), MaintenanceInput::messages())->validate();
        if (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final no puede preceder a la inicial.']);
        }
        if (!empty($filters['tree_id'])) {
            $tree = $this->trees->findForTenant($organization->id, $filters['tree_id']);
            Gate::forUser($actor)->authorize('view', $tree);
            $this->participations->participation($organization->id, $inventory->id, $tree->id);
        }
        $page = $this->maintenances->paginate($organization->id, $filters + [
            'inventory_id' => $inventory->id, 'include_voided_trees' => false,
        ]);
        $records = $page->getCollection()->map(function (Maintenance $record) use ($actor): array {
            Gate::forUser($actor)->authorize('view', $record);

            return ['id' => $record->id, 'code' => $record->code,
                'tree_id' => $record->tree_id, 'tree_code' => $record->tree->code,
                'maintenance_type_id' => $record->maintenance_type_id, 'type_name' => $record->maintenanceType->name,
                'status' => $record->status->value, 'status_label' => $record->status->label(),
                'priority' => $record->priority->value, 'priority_label' => $record->priority->label(),
                'scheduled_date' => $record->scheduled_date?->format('Y-m-d'),
                'performed_date' => $record->performed_date?->format('Y-m-d'),
                'assignee_name' => $record->assignee?->name, 'notes' => $record->notes, 'result' => $record->result,
                'updated_at' => $record->updated_at?->toIso8601String()];
        })->all();

        return ['data' => $records, 'meta' => ['organization_id' => $organization->id,
            'inventory_id' => $inventory->id, 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'has_more' => $page->hasMorePages()]];
    }
}
