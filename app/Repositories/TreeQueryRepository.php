<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\TreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Models\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** Motor único de selección: los Services autorizan; sus consumidores agregan proyección y límites. */
class TreeQueryRepository
{
    public function __construct(private MapScopeRepository $scopes, private InspectionQueryRepository $inspections, private PublicVisibilityRepository $visibility)
    {
    }

    /** Siempre expone latest_inspection sin mezclar sus atributos con los de Tree. No pagina ni ordena. */
    public function query(TreeReadScope $scope, TreeFilterDTO $filters): Builder
    {
        $query = Tree::query()->select('trees.*');
        $this->scopes->applyTo($query, $scope);
        $query->leftJoinLateral($this->inspections->latestForTreeSubquery($scope), 'latest_inspection');
        if ($filters->get('trashed') === 'only') {
            $query->onlyTrashed();
        }
        foreach (['organization_id', 'species_id', 'placement_id', 'growth_habit_id',
            'establishment_origin_id', 'biogeographic_origin_id', 'current_status', 'is_protected', 'is_heritage'] as $key) {
            if ($filters->has($key)) {
                $query->where('trees.'.$key, $filters->get($key));
            }
        }
        if ($filters->has('tree_id')) {
            $query->where('trees.id', $filters->get('tree_id'));
        }
        $this->territoryFilters($query, $filters);
        $this->taxonomyFilters($query, $filters);
        $this->searchFilters($query, $filters, $scope);
        if ($filters->has('inventory_id')) {
            $query->whereExists(function (QueryBuilder $inventory) use ($filters, $scope): void {
                $inventory->selectRaw('1')->from('inventory_trees as matched_inventory')
                    ->whereColumn('matched_inventory.organization_id', 'trees.organization_id')
                    ->whereColumn('matched_inventory.tree_id', 'trees.id')
                    ->where('matched_inventory.inventory_id', $filters->get('inventory_id'));
                if ($scope->kind === 'published') {
                    $inventory->join('inventories as matched_public_inventory', fn ($join) => $join
                        ->on('matched_public_inventory.id', '=', 'matched_inventory.inventory_id')
                        ->on('matched_public_inventory.organization_id', '=', 'matched_inventory.organization_id'));
                    $this->visibility->inventory($inventory, 'matched_public_inventory');
                }
            });
        }
        $inspectionFilters = $filters->values();
        // En este motor la campaña y tree_id pertenecen al individuo, incluso si aún no tiene visitas.
        unset($inspectionFilters['inventory_id'], $inspectionFilters['tree_id']);
        if ($scope->kind === 'published') {
            unset($inspectionFilters['risk_priority']);
        }
        $this->inspections->applyFilters($query, $inspectionFilters, 'latest_inspection');
        $this->maintenanceFilters($query, $filters, $scope);

        return $query;
    }

    public function ordered(Builder $query, TreeFilterDTO $filters): Builder
    {
        $direction = $filters->get('direction') === 'desc' ? 'desc' : 'asc';
        if ($filters->get('sort') === 'scientific_name') {
            $query->leftJoin('species as sorted_species', 'sorted_species.id', '=', 'trees.species_id')
                ->orderBy('sorted_species.scientific_name', $direction);
        } elseif ($filters->get('sort') === 'common_name') {
            $query->orderBy(\Illuminate\Support\Facades\DB::table('species_common_names')
                ->select('name')->whereColumn('species_id', 'trees.species_id')
                ->orderByDesc('is_primary')->orderBy('name')->orderBy('id')->limit(1), $direction);
        } elseif ($filters->get('sort') === 'municipality') {
            $query->leftJoin('organizations as sorted_organization', 'sorted_organization.id', '=', 'trees.organization_id')
                ->leftJoin('municipalities as sorted_municipality', 'sorted_municipality.id', '=', 'sorted_organization.municipality_id')
                ->orderBy('sorted_municipality.name', $direction);
        } elseif ($filters->get('sort') === 'latest_inspection_date') {
            // La dirección proviene exclusivamente de la lista cerrada anterior.
            $query->orderByRaw('latest_inspection.inspection_date '.$direction.' NULLS LAST');
        } else {
            $query->orderBy('trees.'.($filters->get('sort') === 'created_at' ? 'created_at' : 'code'), $direction);
        }

        return $query->orderBy('trees.id');
    }

    private function territoryFilters(Builder $query, TreeFilterDTO $filters): void
    {
        if ($filters->has('municipality_id')) {
            $query->whereHas('organization', fn (Builder $organization) => $organization->where('municipality_id', $filters->get('municipality_id')));
        }
        if ($filters->has('department_id')) {
            $query->whereHas('organization.municipality', fn (Builder $municipality) => $municipality->where('department_id', $filters->get('department_id')));
        }
    }

    private function taxonomyFilters(Builder $query, TreeFilterDTO $filters): void
    {
        if ($filters->has('family_id')) {
            $query->whereHas('species', fn (Builder $species) => $species->where('family_id', $filters->get('family_id')));
        }
        if ($filters->has('common_name_id')) {
            $query->whereHas('species.commonNames', fn (Builder $names) => $names->whereKey($filters->get('common_name_id')));
        }
        if ($filters->has('scientific_name')) {
            $query->whereHas('species', fn (Builder $species) => $species->where('scientific_name', 'ilike', $this->literalSearch($filters->get('scientific_name'))));
        }
        if ($filters->has('common_name')) {
            $query->whereHas('species.commonNames', fn (Builder $names) => $names->where('name', 'ilike', $this->literalSearch($filters->get('common_name'))));
        }
    }

    private function searchFilters(Builder $query, TreeFilterDTO $filters, TreeReadScope $scope): void
    {
        if ($filters->has('code')) {
            $query->where('trees.code', 'ilike', $this->literalSearch($filters->get('code')));
        }
        if ($filters->has('q')) {
            $term = $this->literalSearch($filters->get('q'));
            $query->where(function (Builder $query) use ($term, $scope): void {
                $query->where('trees.code', 'ilike', $term);
                if ($scope->kind !== 'published') {
                    $query->orWhere('trees.external_number', 'ilike', $term)->orWhere('trees.location_description', 'ilike', $term);
                }
                $query->orWhereHas('species', fn (Builder $species) => $species->where('scientific_name', 'ilike', $term)
                    ->orWhereHas('commonNames', fn (Builder $names) => $names->where('name', 'ilike', $term)));
            });
        }
    }

    private function maintenanceFilters(Builder $query, TreeFilterDTO $filters, TreeReadScope $scope): void
    {
        if (!$filters->hasMaintenanceFilters()) {
            return;
        }
        $query->whereExists(function (QueryBuilder $maintenance) use ($filters, $scope): void {
            $maintenance->selectRaw('1')->from('maintenances as matched_maintenance')
                ->whereColumn('matched_maintenance.organization_id', 'trees.organization_id')
                ->whereColumn('matched_maintenance.tree_id', 'trees.id')->whereNull('matched_maintenance.deleted_at');
            $this->applyMaintenanceFilters($maintenance, $filters, $scope, 'matched_maintenance');
        });
    }

    /** Predicado único para seleccionar árboles y agregar las mismas órdenes que motivaron su selección. */
    public function applyMaintenanceFilters(Builder|QueryBuilder $query, TreeFilterDTO $filters, TreeReadScope $scope, string $alias): void
    {
        if (!preg_match('/\A[a-z_][a-z0-9_]*\z/D', $alias)) {
            throw new \InvalidArgumentException('El alias interno de mantenimiento no es válido.');
        }
        $query->whereNull($alias.'.deleted_at');
        if ($scope->kind === 'published') {
            $this->visibility->maintenance($query, $alias);
        }
        foreach (['maintenance_type_id' => 'maintenance_type_id', 'maintenance_status' => 'status', 'maintenance_priority' => 'priority'] as $key => $column) {
            if ($filters->has($key)) {
                $query->where($alias.'.'.$column, $filters->get($key));
            }
        }
        if (!$filters->has('maintenance_status')) {
            $query->where($alias.'.status', '<>', 'cancelled');
        }
        // False conserva la semántica existente: no significa «sin pendientes».
        if ($filters->get('has_pending_maintenance') === true) {
            $query->whereIn($alias.'.status', ['pending', 'scheduled']);
        }
    }

    private function literalSearch(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
