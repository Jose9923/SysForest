<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\InventoryTree;
use App\Models\Tree;
use App\Models\TreeInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class TreeInspectionRepository
{
    private const RELATIONS = ['tree.species.family', 'tree.species.commonNames', 'inventoryTree.inventory',
        'creator', 'updater', 'classificationRuleVersion', 'maturityState', 'conditions.category', 'faunaCategories'];
    private const IMMUTABLE = ['id', 'organization_id', 'tree_id', 'created_by', 'created_at',
        'classification_rule_version_id', 'calculation_rule_version'];

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

        return $this->query($organizationId)->whereIn('tree_id', array_unique($treeIds))
            ->selectRaw('DISTINCT ON (tree_inspections.tree_id) tree_inspections.*')
            ->orderBy('tree_inspections.tree_id')->orderByDesc('inspection_date')->orderByDesc('created_at')->orderByDesc('id')
            ->with(self::RELATIONS)->get()->keyBy('tree_id');
    }

    public function paginate(string $organizationId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query($organizationId)->with(self::RELATIONS)
            ->whereHas('tree', fn (Builder $tree) => ($filters['trashed'] ?? '') === 'only'
                ? $tree->whereNotNull('trees.deleted_at') : $tree->whereNull('trees.deleted_at'));
        $this->inspectionFilters($query, $organizationId, $filters, 'tree_inspections');
        if (!empty($filters['species_id'])) {
            $query->whereHas('tree', fn (Builder $tree) => $tree->where('species_id', $filters['species_id']));
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
        $latest = DB::table('tree_inspections')->where('organization_id', $organizationId)
            ->selectRaw('DISTINCT ON (tree_id) id, organization_id, tree_id')
            ->orderBy('tree_id')->orderByDesc('inspection_date')->orderByDesc('created_at')->orderByDesc('id');
        $query = Tree::query()->where('trees.organization_id', $organizationId)
            ->leftJoinSub($latest, 'latest_visit', fn ($join) => $join->on('latest_visit.tree_id', '=', 'trees.id')
                ->on('latest_visit.organization_id', '=', 'trees.organization_id'))
            ->leftJoin('tree_inspections as latest_inspection', function ($join): void {
                $join->on('latest_inspection.id', '=', 'latest_visit.id')
                    ->on('latest_inspection.organization_id', '=', 'trees.organization_id');
            })->select('trees.*')
            ->with(['species.family', 'species.commonNames', 'placement', 'growthHabit',
                'establishmentOrigin', 'biogeographicOrigin', 'inventories', 'creator', 'updater', 'deletionReason']);
        if (($filters['trashed'] ?? '') === 'only') {
            $query->onlyTrashed();
        }
        foreach (['species_id', 'placement_id', 'current_status'] as $field) {
            if (!empty($filters[$field])) {
                $query->where('trees.'.$field, $filters[$field]);
            }
        }
        if (!empty($filters['inventory_id'])) {
            $query->whereHas('inventories', fn (Builder $inventories) => $inventories
                ->where('inventories.organization_id', $organizationId)->where('inventories.id', $filters['inventory_id']));
        }
        $inspectionFilters = $filters;
        unset($inspectionFilters['inventory_id']);
        $this->inspectionFilters($query, $organizationId, $inspectionFilters, 'latest_inspection');
        if (isset($filters['q']) && $filters['q'] !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn (Builder $q) => $q->where('trees.code', 'ilike', $term)
                ->orWhere('trees.external_number', 'ilike', $term)->orWhere('trees.location_description', 'ilike', $term)
                ->orWhereHas('species', fn (Builder $species) => $species->where('scientific_name', 'ilike', $term)
                    ->orWhereHas('commonNames', fn (Builder $names) => $names->where('name', 'ilike', $term))));
        }
        $direction = ($filters['direction'] ?? '') === 'desc' ? 'desc' : 'asc';
        if (($filters['sort'] ?? '') === 'scientific_name') {
            $query->leftJoin('species as sorted_species', 'sorted_species.id', '=', 'trees.species_id')
                ->orderBy('sorted_species.scientific_name', $direction);
        } else {
            $query->orderBy('trees.'.(($filters['sort'] ?? '') === 'created_at' ? 'created_at' : 'code'), $direction);
        }
        $page = $query->orderBy('trees.id')->paginate(20)->withQueryString();
        $visits = $this->latestForTrees($organizationId, $page->getCollection()->modelKeys());
        foreach ($page->getCollection() as $tree) {
            // Relación preparada para Blade; no agrega una carga perezosa por cada individuo.
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
            if (!Str::isUuid($value)) {
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
        return $query->orderByDesc('tree_inspections.inspection_date')->orderByDesc('tree_inspections.created_at')
            ->orderByDesc('tree_inspections.id');
    }

    private function inspectionFilters(Builder $query, string $organizationId, array $filters, string $alias): void
    {
        if (!empty($filters['tree_id'])) {
            $query->where($alias.'.tree_id', $filters['tree_id']);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (!empty($filters[$key])) {
                $query->where($alias.'.inspection_date', $operator, $filters[$key]);
            }
        }
        foreach (['maturity_state_id', 'diameter_class_number', 'height_class_number'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($alias.'.'.$field, $filters[$field]);
            }
        }
        foreach (['cap_cm', 'dap_m', 'total_height_m'] as $field) {
            foreach (['min' => '>=', 'max' => '<='] as $suffix => $operator) {
                if (isset($filters[$field.'_'.$suffix]) && $filters[$field.'_'.$suffix] !== '') {
                    $query->where($alias.'.'.$field, $operator, $filters[$field.'_'.$suffix]);
                }
            }
        }
        if (!empty($filters['inventory_id'])) {
            $query->whereExists(fn (QueryBuilder $inventory) => $inventory->selectRaw('1')->from('inventory_trees as inspection_campaign')
                ->whereColumn('inspection_campaign.id', $alias.'.inventory_tree_id')
                ->whereColumn('inspection_campaign.tree_id', $alias.'.tree_id')
                ->where('inspection_campaign.organization_id', $organizationId)
                ->where('inspection_campaign.inventory_id', $filters['inventory_id']));
        }
        $ids = array_values(array_unique(array_merge($filters['physical_condition_ids'] ?? [], $filters['phytosanitary_condition_ids'] ?? [])));
        if ($ids !== []) {
            $conditions = fn (QueryBuilder $conditions) => $conditions->from('inspection_conditions as matched_condition')
                ->where('matched_condition.organization_id', $organizationId)
                ->whereColumn('matched_condition.inspection_id', $alias.'.id')->whereIn('matched_condition.condition_id', $ids);
            if (($filters['condition_mode'] ?? 'any') === 'all') {
                $query->where(fn (QueryBuilder $count) => $conditions($count)->selectRaw('COUNT(DISTINCT matched_condition.condition_id)'), '=', count($ids));
            } else {
                $query->whereExists(fn (QueryBuilder $exists) => $conditions($exists)->selectRaw('1'));
            }
        }
    }

    private function requireUuid(string $id): void
    {
        if (!Str::isUuid($id)) {
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
