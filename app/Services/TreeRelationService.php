<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\TreeCatalogRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TreeRelationService
{
    public function __construct(private InventoryRepository $inventories, private TreeCatalogRepository $catalogs, private AuditSnapshotService $snapshots, private \App\Repositories\SecurityEventRepository $events)
    {
    }

    /** Una referencia inactiva ya usada se conserva; una nueva selección requiere disponibilidad vigente. */
    public function validateCatalogs(array $data, ?Tree $tree = null): void
    {
        foreach (['placement_id', 'species_id', 'growth_habit_id', 'establishment_origin_id', 'biogeographic_origin_id'] as $field) {
            if (!empty($data[$field]) && !$this->catalogs->validSelection($field, $data[$field], $tree?->getAttribute($field))) {
                throw ValidationException::withMessages([$field => 'La referencia seleccionada no está disponible.']);
            }
        }
    }

    /** Añade participaciones sin reemplazar ni eliminar campañas históricas del individuo. */
    public function include(Tree $tree, User $actor, array $ids, ?string $notes = null): void
    {
        foreach ($ids as $id) {
            try {
                $inventory = $this->inventories->findForTenant($tree->organization_id, $id, true);
            } catch (ModelNotFoundException) {
                throw ValidationException::withMessages(['inventory_ids' => 'El inventario no está disponible en la organización seleccionada.']);
            }
            $status = $inventory->status instanceof InventoryStatus ? $inventory->status->value : $inventory->status;
            if (!$inventory->active || $status !== 'active') {
                throw ValidationException::withMessages(['inventory_ids' => 'Solo se pueden añadir individuos a inventarios activos.']);
            }
            $participation = $this->inventories->attach($tree->organization_id, $inventory->id, $tree->id, (int) $actor->id, $notes);
            if ($participation->wasRecentlyCreated) {
                $this->events->recordChange(
                    'inventory.tree_included',
                    $actor,
                    $tree->organization_id,
                    'inventory_tree',
                    $participation->id,
                    [],
                    $this->snapshots->snapshot($participation),
                    ['resource_id' => $participation->id]
                );
            }
        }
    }
}
