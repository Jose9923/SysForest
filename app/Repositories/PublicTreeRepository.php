<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\PublicTreeFilterDTO;
use App\DTOs\TreeFilterDTO;
use App\DTOs\TreeReadScope;
use App\Enums\TreeStatus;
use App\Models\Evidence;
use App\Models\Maintenance;
use App\Models\Tree;
use App\Models\TreeInspection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Proyecciones públicas explícitas sobre el motor común; los Resources deciden la forma de salida. */
class PublicTreeRepository
{
    private const TREE_FIELDS = ['trees.id', 'trees.organization_id', 'trees.code', 'trees.species_id',
        'trees.placement_id', 'trees.growth_habit_id', 'trees.establishment_origin_id', 'trees.biogeographic_origin_id',
        'trees.current_status', 'trees.is_protected', 'trees.is_heritage'];
    private const TREE_RELATIONS = ['species:id,family_id,scientific_name', 'species.family:id,scientific_name',
        'placement:id,name', 'growthHabit:id,name',
        'establishmentOrigin:id,name', 'biogeographicOrigin:id,name',
        'organization:id,public_code,name,municipality_id', 'organization.municipality:id,department_id,code,name',
        'organization.municipality.department:id,country_id,code,name', 'organization.municipality.department.country:id,code,name'];
    private const INSPECTION_FIELDS = ['id', 'organization_id', 'tree_id', 'inspection_date', 'maturity_state_id',
        'cap_cm', 'number_of_stems', 'form_factor', 'total_height_m', 'commercial_height_m', 'crown_x_m', 'crown_y_m',
        'dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3',
        'diameter_class_number', 'height_class_number', 'flowering_date', 'fruiting_date'];

    public function __construct(
        private TreeQueryRepository $trees,
        private MapTreeRepository $map,
        private MapScopeRepository $scopes,
        private InspectionQueryRepository $inspectionsQuery,
        private PublicVisibilityRepository $visibility,
    ) {
    }

    /** El viewport limita el dibujo; la tabla y los indicadores conservan el universo filtrado completo. */
    public function query(PublicTreeFilterDTO $filters): Builder
    {
        return $this->map->query(TreeReadScope::published(), $filters->map);
    }

    public function paginate(PublicTreeFilterDTO $filters, int $perPage = 20): LengthAwarePaginator
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('La página pública requiere entre 1 y 100 registros.');
        }
        $query = $this->projectTrees($this->query($filters));

        return $this->trees->ordered($query, $filters->trees)
            ->paginate($perPage, ['*'], 'page', (int) ($filters->publicValues['page'] ?? 1))
            ->appends(array_diff_key($filters->publicValues, ['page' => true]));
    }

    public function indicators(PublicTreeFilterDTO $filters): array
    {
        $filtered = $this->query($filters)->select(['trees.id', 'trees.species_id', 'trees.organization_id',
            'trees.current_status', 'trees.geometry']);
        $row = DB::query()->fromSub($filtered->toBase(), 'public_trees')
            ->leftJoin('species as public_species', 'public_species.id', '=', 'public_trees.species_id')
            ->join('organizations as public_organization', 'public_organization.id', '=', 'public_trees.organization_id')
            ->selectRaw('COUNT(*) AS trees, COUNT(public_trees.geometry) AS georeferenced, '
                .'COUNT(DISTINCT public_trees.species_id) AS species, COUNT(DISTINCT public_species.family_id) AS families, '
                .'COUNT(DISTINCT public_organization.municipality_id) AS municipalities, '
                .'COUNT(DISTINCT public_trees.organization_id) AS organizations')->first();
        $states = DB::query()->fromSub($filtered->toBase(), 'public_trees')->select('current_status')
            ->selectRaw('COUNT(*) AS count')->groupBy('current_status')->pluck('count', 'current_status');

        return ['trees' => (int) $row->trees, 'georeferenced' => (int) $row->georeferenced,
            'without_geometry' => (int) $row->trees - (int) $row->georeferenced,
            'species' => (int) $row->species, 'families' => (int) $row->families,
            'municipalities' => (int) $row->municipalities, 'organizations' => (int) $row->organizations,
            'states' => array_map(fn (TreeStatus $status) => ['value' => $status->value,
                'label' => $status->label(), 'count' => (int) ($states[$status->value] ?? 0)], TreeStatus::cases())];
    }

    public function find(string $organizationPublicCode, string $treeCode): Tree
    {
        $query = $this->trees->query(TreeReadScope::published(), TreeFilterDTO::fromValidated([]))
            ->where('trees.code', $treeCode)
            ->whereHas('organization', fn (Builder $organization) => $organization->where('public_code', $organizationPublicCode));
        $tree = $this->projectTrees($query)->firstOrFail();
        $tree->setRelation('publicLatestInspection', $this->inspectionsQuery->ordered($this->inspectionQuery($tree))->first());

        return $tree;
    }

    public function inspections(Tree $tree, int $page = 1): LengthAwarePaginator
    {
        return $this->inspectionsQuery->ordered($this->inspectionQuery($tree))
            ->paginate(15, ['*'], 'inspections_page', max(1, min(1000, $page)));
    }

    public function maintenances(Tree $tree, int $page = 1): LengthAwarePaginator
    {
        return $this->maintenanceQuery($tree)->orderByDesc('performed_date')->orderByDesc('id')
            ->paginate(15, ['*'], 'maintenances_page', max(1, min(1000, $page)));
    }

    public function evidences(Tree $tree, int $page = 1): LengthAwarePaginator
    {
        return $this->evidenceQuery($tree)->select(['id', 'public_id', 'evidence_type_id', 'public_caption', 'captured_at', 'width', 'height'])
            ->orderByDesc('captured_at')->orderByDesc('id')
            ->paginate(12, ['*'], 'evidences_page', max(1, min(1000, $page)));
    }

    public function inspectionQuery(Tree $tree): Builder
    {
        $query = TreeInspection::query()->where('organization_id', $tree->organization_id)->where('tree_id', $tree->id)
            ->select(self::INSPECTION_FIELDS)->with(['maturityState:id,name',
                'conditions:id,code,name,condition_category_id,assessment_type', 'conditions.category:id,name',
                'faunaCategories:id,code,name']);
        $this->visibility->inspection($query);
        $query->whereExists($this->ownerTree('tree_inspections.tree_id', 'tree_inspections.organization_id', $tree)->toBase());

        return $query;
    }

    public function maintenanceQuery(Tree $tree): Builder
    {
        $query = Maintenance::query()->where('organization_id', $tree->organization_id)->where('tree_id', $tree->id)
            ->select(['id', 'organization_id', 'tree_id', 'maintenance_type_id', 'status', 'performed_date'])
            ->with('maintenanceType:id,code,name');
        $this->visibility->maintenance($query);
        $query->whereExists($this->ownerTree('maintenances.tree_id', 'maintenances.organization_id', $tree)->toBase());

        return $query;
    }

    /** Revalida las tres cadenas de propietario; una bandera aislada nunca hace público un archivo. */
    public function evidenceQuery(?Tree $tree = null): Builder
    {
        $inspection = TreeInspection::query()->selectRaw('1')
            ->whereColumn('tree_inspections.id', 'evidences.tree_inspection_id')
            ->whereColumn('tree_inspections.organization_id', 'evidences.organization_id')
            ->whereExists($this->ownerTree('tree_inspections.tree_id', 'tree_inspections.organization_id', $tree)->toBase());
        $this->visibility->inspection($inspection);
        $maintenance = Maintenance::query()->selectRaw('1')
            ->whereColumn('maintenances.id', 'evidences.maintenance_id')
            ->whereColumn('maintenances.organization_id', 'evidences.organization_id')
            ->whereExists($this->ownerTree('maintenances.tree_id', 'maintenances.organization_id', $tree)->toBase());
        $this->visibility->maintenance($maintenance);

        return Evidence::query()->where('evidences.is_public', true)->with('evidenceType:id,code,name')
            ->where(fn (Builder $owner) => $owner
                ->whereExists($this->ownerTree('evidences.tree_id', 'evidences.organization_id', $tree)->toBase())
                ->orWhereExists($inspection->toBase())->orWhereExists($maintenance->toBase()));
    }

    public function findEvidence(string $publicId): Evidence
    {
        if (!Str::isUlid($publicId)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Evidence::class);
        }

        return $this->evidenceQuery()->where('public_id', $publicId)->firstOrFail();
    }

    private function projectTrees(Builder $query): Builder
    {
        return $query->select(self::TREE_FIELDS)->selectRaw('ST_X(trees.geometry) AS longitude, ST_Y(trees.geometry) AS latitude, '
            .'latest_inspection.inspection_date AS latest_inspection_date')->with(self::TREE_RELATIONS)
            ->with(['species.commonNames' => fn ($names) => $names->select('id', 'species_id', 'name', 'is_primary')
                ->orderByDesc('is_primary')->orderBy('name')->orderBy('id')]);
    }

    private function ownerTree(string $treeColumn, string $organizationColumn, ?Tree $tree): Builder
    {
        $query = Tree::query()->selectRaw('1')->whereColumn('trees.id', $treeColumn)
            ->whereColumn('trees.organization_id', $organizationColumn);
        if ($tree !== null) {
            $query->where('trees.id', $tree->id)->where('trees.organization_id', $tree->organization_id);
        }
        $this->scopes->applyTo($query, TreeReadScope::published());

        return $query;
    }
}
