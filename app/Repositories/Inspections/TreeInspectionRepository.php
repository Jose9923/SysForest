<?php

declare(strict_types=1);

namespace App\Repositories\Inspections;

use App\DTOs\Trees\TreeFilterDTO;
use App\DTOs\Trees\TreeReadScope;
use App\Models\Inventories\InventoryTree;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use App\Repositories\Trees\TreeQueryRepository;

class TreeInspectionRepository
{
    private const RELATIONS = ['tree.growthHabit', 'tree.species.family', 'tree.species.commonNames', 'inventoryTree.inventory',
        'carbonEstimate', 'riskEvaluation.confirmer', 'riskEvaluation.methodology', 'creator', 'updater', 'classificationRuleVersion', 'maturityState', 'conditions.category', 'faunaCategories'];
    private const IMMUTABLE = ['id', 'organization_id', 'tree_id', 'created_by', 'created_at',
        'classification_rule_version_id', 'calculation_rule_version'];

    public function __construct(private TreeQueryRepository $trees, private InspectionQueryRepository $queries)
    {
    }

    public function findForTenant(string $organizationId, string $id, bool $lock = false): TreeInspection
    {
        $this->requireUuid($id);
        $query = $this->query($organizationId)->whereKey($id);
        $inspection = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();

        return $inspection->load(self::RELATIONS);
    }

    /** El Service entrega exclusivamente entradas validadas y derivados recalculados. */
    public function create(array $attributes): TreeInspection
    {
        $this->requireTransaction();
        $inspection = new TreeInspection();
        $inspection->forceFill($attributes)->save();

        return $this->findForTenant($inspection->organization_id, $inspection->id);
    }

    public function update(TreeInspection $inspection, array $attributes): TreeInspection
    {
        $this->requireTransaction();
        foreach (self::IMMUTABLE as $key) {
            unset($attributes[$key]);
        }
        $inspection->forceFill($attributes)->save();

        return $this->findForTenant($inspection->organization_id, $inspection->id);
    }

    public function timelineForTree(string $organizationId, string $treeId): Collection
    {
        $this->requireUuid($treeId);

        return $this->ordered($this->query($organizationId)->where('tree_id', $treeId))->with(self::RELATIONS)->get();
    }

    public function latestForTree(string $organizationId, string $treeId): ?TreeInspection
    {
        $this->requireUuid($treeId);

        return $this->ordered($this->query($organizationId)->where('tree_id', $treeId))->with(self::RELATIONS)->first();
    }

    /** Una visita por árbol, resuelta en PostgreSQL y sin MAX(UUID) ni consultas por cada fila. */
    public function latestForTrees(string $organizationId, array $treeIds): Collection
    {
        $this->requireUuid($organizationId);
        foreach ($treeIds as $id) {
            $this->requireUuid($id);
        }
        if ($treeIds === []) {
            return new Collection();
        }

        $query = $this->query($organizationId)->whereIn('tree_id', array_unique($treeIds))
            ->selectRaw('DISTINCT ON (tree_inspections.tree_id) tree_inspections.*')->orderBy('tree_inspections.tree_id');

        return $this->queries->ordered($query)->with(self::RELATIONS)->get()->keyBy('tree_id');
    }

    public function paginate(string $organizationId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query($organizationId)->with(self::RELATIONS)
            ->whereHas('tree', fn (Builder $tree) => ($filters['trashed'] ?? '') === 'only'
                ? $tree->whereNotNull('trees.deleted_at') : $tree->whereNull('trees.deleted_at'));
        $this->queries->applyFilters($query, $filters);
        if (!empty($filters['species_id'])) {
            $query->whereHas('tree', fn (Builder $tree) => $tree->where('species_id', $filters['species_id']));
        }

        if (!empty($filters['common_name_id'])) {
            $query->whereHas('tree.species.commonNames', fn (Builder $name) => $name->whereKey($filters['common_name_id']));
        }

        return $this->ordered($query)->paginate(20)->withQueryString();
    }

    /**
     * Selecciona primero la última visita global del árbol y después filtra sus valores.
     * inventory_id conserva aquí la participación del Tree de Fase 6; no redefine qué visita es la última.
     */
    public function latestTrees(string $organizationId, array $filters = []): LengthAwarePaginator
    {
        $this->requireUuid($organizationId);
        $criteria = TreeFilterDTO::fromValidated($filters);
        $query = $this->trees->query(TreeReadScope::organization($organizationId), $criteria)
            ->with(['species.family', 'species.commonNames', 'placement', 'growthHabit',
                'establishmentOrigin', 'biogeographicOrigin', 'inventories', 'creator', 'updater', 'deletionReason']);
        $page = $this->trees->ordered($query, $criteria)->paginate(20)->withQueryString();
        $visits = $this->latestForTrees($organizationId, $page->getCollection()->modelKeys());
        foreach ($page->getCollection() as $tree) {
            // Mantiene el contrato de Blade y evita consultas perezosas por individuo.
            $tree->setRelation('latestInspection', $visits->get($tree->id));
        }

        return $page;
    }

    /** Una campaña cerrada sigue siendo un contexto válido para una visita histórica. */
    public function inventoryOptionsForTree(string $organizationId, string $treeId): Collection
    {
        $this->requireUuid($organizationId);
        $this->requireUuid($treeId);

        return InventoryTree::query()->where('organization_id', $organizationId)->where('tree_id', $treeId)
            ->with('inventory')->orderBy('included_at')->orderBy('id')->get();
    }

    public function validateInventoryTree(string $organizationId, string $treeId, string $id, bool $lock = false): ?InventoryTree
    {
        foreach ([$organizationId, $treeId, $id] as $value) {
            if (!\App\Support\Identifiers\RecordIdentifier::isValid($value)) {
                return null;
            }
        }
        $query = InventoryTree::query()->where('organization_id', $organizationId)->where('tree_id', $treeId)->whereKey($id)
            ->with('inventory');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function query(string $organizationId): Builder
    {
        $this->requireUuid($organizationId);

        return TreeInspection::query()->where('tree_inspections.organization_id', $organizationId);
    }

    private function ordered(Builder $query): Builder
    {
        return $this->queries->ordered($query);
    }

    private function requireUuid(string $id): void
    {
        if (!\App\Support\Identifiers\RecordIdentifier::isValid($id)) {
            throw (new ModelNotFoundException())->setModel(TreeInspection::class);
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Las inspecciones y sus relaciones requieren una transacción.');
        }
    }
}
