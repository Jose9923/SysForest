<?php

declare(strict_types=1);

namespace App\Repositories\Reports;

use App\DTOs\Reports\ReportSelectionDTO;
use App\DTOs\Trees\TreeFilterDTO;
use App\Models\Evidences\Evidence;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Reports\InventoryPrivateRowProjector;
use App\Reports\InventoryPublicRowProjector;
use App\Reports\RiskTreeRowProjector;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Repositories\Maps\MapTreeRepository;
use App\Repositories\PublicPortal\PublicTreeRepository;
use App\Repositories\PublicPortal\PublicVisibilityRepository;
use App\Repositories\Trees\TreeQueryRepository;

/** Lectura por lotes del mismo universo del mapa; no autoriza, escribe archivos ni recalcula dasometría. */
class ExportReadRepository
{
    private const TREE_FIELDS = ['trees.id', 'trees.organization_id', 'trees.code', 'trees.species_id',
        'trees.placement_id', 'trees.growth_habit_id', 'trees.establishment_origin_id', 'trees.biogeographic_origin_id',
        'trees.current_status', 'trees.is_protected', 'trees.is_heritage'];

    private const PRIVATE_TREE_FIELDS = ['trees.external_number', 'trees.coordinate_raw', 'trees.coordinate_format',
        'trees.datum', 'trees.accuracy_m', 'trees.gps_brand', 'trees.gps_model', 'trees.captured_at', 'trees.location_description'];

    private const INSPECTION_FIELDS = ['id', 'organization_id', 'tree_id', 'inspection_date', 'maturity_state_id',
        'cap_cm', 'number_of_stems', 'form_factor', 'total_height_m', 'commercial_height_m', 'crown_x_m', 'crown_y_m',
        'dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3',
        'diameter_class_number', 'height_class_number', 'flowering_date', 'fruiting_date'];

    private const PRIVATE_INSPECTION_FIELDS = ['lock_version', 'classification_rule_version_id', 'calculation_rule_version',
        'flowering_development', 'fruiting_development', 'fauna_observations', 'general_observations'];

    public function __construct(
        private MapTreeRepository $map,
        private TreeQueryRepository $trees,
        private PublicTreeRepository $publicTrees,
        private PublicVisibilityRepository $publication,
        private InventoryPrivateRowProjector $privateProjector,
        private InventoryPublicRowProjector $publicProjector,
        private RiskTreeRowProjector $riskProjector,
    ) {
    }

    public function count(ReportSelectionDTO $selection): int
    {
        return $this->map->query($selection->scope, $selection->filters, false)->count();
    }

    /**
     * @return iterable<array<string, scalar|array|null>>
     * El servicio que genera el archivo mantiene el snapshot y comprueba el límite de descarga.
     * Los metadatos con prefijo _ nunca forman parte del esquema entregado al usuario.
     */
    public function rows(ReportSelectionDTO $selection, int $batchSize = 200, bool $riskOnly = false): iterable
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('El lote debe contener entre 1 y 1000 árboles.');
        }
        $query = $this->ordered($selection, $riskOnly);
        for ($offset = 0; ; $offset += $batchSize) {
            $batch = (clone $query)->offset($offset)->limit($batchSize)->get();
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($this->projectBatch($selection, $batch, $riskOnly) as $row) {
                yield $row;
            }
            if ($batch->count() < $batchSize) {
                break;
            }
        }
    }

    /** Una página real de SQL; nunca recorre primero las filas de las páginas anteriores. */
    public function paginate(ReportSelectionDTO $selection, int $page = 1, int $perPage = 25, bool $riskOnly = false): LengthAwarePaginator
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('La página debe ser positiva y contener entre 1 y 100 árboles.');
        }
        $paginator = $this->ordered($selection, $riskOnly)->paginate($perPage, ['*'], 'page', $page);
        $paginator->setCollection(collect($this->projectBatch($selection, $paginator->getCollection(), $riskOnly)));

        return $paginator->appends(array_diff_key($selection->values, ['page' => true]));
    }

    /** La ficha conserva todos los filtros y el alcance: una identidad no amplía la selección. */
    public function find(ReportSelectionDTO $selection, string $treeIdOrCode, ?string $organizationPublicCode = null): ?array
    {
        $query = $this->projectQuery($selection);
        if ($selection->isPublic()) {
            if ($organizationPublicCode === null) {
                return null;
            }
            $query->where('trees.code', $treeIdOrCode)->whereHas(
                'organization',
                fn (Builder $organization) => $organization->where('public_code', $organizationPublicCode)
            );
        } elseif (Str::isUuid($treeIdOrCode)) {
            $query->where('trees.id', $treeIdOrCode);
        } else {
            // Un código puede repetirse entre organizaciones; no se escoge arbitrariamente una coincidencia.
            if ($selection->scope->kind !== 'organization' && $organizationPublicCode === null) {
                return null;
            }
            $query->where('trees.code', $treeIdOrCode);
            if ($organizationPublicCode !== null) {
                $query->whereHas('organization', fn (Builder $organization) => $organization->where('code', $organizationPublicCode));
            }
        }
        $tree = $query->first();

        return $tree === null ? null : $this->projectBatch($selection, new ModelCollection([$tree]))[0];
    }

    private function ordered(ReportSelectionDTO $selection, bool $riskOnly = false): Builder
    {
        $sort = TreeFilterDTO::fromValidated(array_replace(
            $selection->filters->trees->values(),
            array_intersect_key($selection->values, ['sort' => true, 'direction' => true])
        ));

        return $this->trees->ordered($this->projectQuery($selection, $riskOnly), $sort);
    }

    private function projectQuery(ReportSelectionDTO $selection, bool $riskOnly = false): Builder
    {
        abort_if($riskOnly && $selection->isPublic(), 404);
        $fields = self::TREE_FIELDS;
        if (!$selection->isPublic()) {
            $fields = array_merge($fields, self::PRIVATE_TREE_FIELDS);
        }
        $organizationFields = $selection->isPublic() ? 'id,public_code,name,municipality_id' : 'id,code,name,municipality_id';

        return $this->map->query($selection->scope, $selection->filters, false)->select($fields)
            ->selectRaw('ST_X(trees.geometry) AS longitude, ST_Y(trees.geometry) AS latitude, latest_inspection.id AS export_inspection_id')
            ->with(['species:id,family_id,scientific_name', 'species.family:id,scientific_name',
                'species.commonNames' => fn ($names) => $names->select('id', 'species_id', 'name', 'is_primary')
                    ->orderByDesc('is_primary')->orderBy('name')->orderBy('id'),
                'placement:id,name', 'growthHabit:id,name', 'establishmentOrigin:id,name', 'biogeographicOrigin:id,name',
                'organization:'.$organizationFields, 'organization.municipality:id,department_id,code,name',
                'organization.municipality.department:id,country_id,code,name',
                'organization.municipality.department.country:id,code,name']);
    }

    /** @return list<array> */
    private function projectBatch(ReportSelectionDTO $selection, ModelCollection $batch, bool $riskOnly = false): array
    {
        if ($batch->isEmpty()) {
            return [];
        }
        $inspections = $this->inspections($selection, $batch, $riskOnly);
        $evidences = $riskOnly ? collect() : $this->evidences($selection, $inspections);
        $rows = [];
        foreach ($batch as $tree) {
            $inspection = $inspections->get($tree->getAttribute('export_inspection_id'));
            if ($inspection !== null && ($inspection->organization_id !== $tree->organization_id || $inspection->tree_id !== $tree->id)) {
                throw new \LogicException('La visita seleccionada no pertenece al árbol y organización del lote.');
            }
            $photos = $inspection === null ? collect() : ($evidences->get($inspection->id) ?? collect());
            $row = $riskOnly ? $this->riskProjector->project($tree, $inspection)
                : ($selection->isPublic() ? $this->publicProjector->project($tree, $inspection, $photos)
                    : $this->privateProjector->project($tree, $inspection, $photos));
            $row['_geometry'] = $tree->getAttribute('longitude') === null ? null : [
                'type' => 'Point', 'coordinates' => [(float) $tree->getAttribute('longitude'), (float) $tree->getAttribute('latitude')],
            ];
            $organization = $tree->getRelation('organization');
            $organizationCode = $selection->isPublic() ? $organization->public_code : $organization->code;
            $row['_identity'] = rawurlencode($organizationCode).'/'.rawurlencode($tree->code);
            $row['_source'] = ['tree_id' => $tree->id, 'inspection_id' => $inspection?->id,
                'evidence_ids' => $photos->pluck('id')->all(), 'maintenance_ids' => []];
            if ($riskOnly) {
                $risk = $inspection?->getRelation('riskEvaluation');
                $row['_source']['inspection_version'] = $inspection?->lock_version;
                $row['_source']['risk_group'] = $risk === null ? 'unevaluated'
                    : (($risk->result['status'] ?? '') === 'inconsistent' ? 'inconsistent'
                        : ($risk->priority === null ? 'pending' : (string) $risk->priority));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Precarga los IDs ya escogidos por LATERAL; no vuelve a elegir otra visita por organización. */
    private function inspections(ReportSelectionDTO $selection, ModelCollection $batch, bool $riskOnly = false): Collection
    {
        $selected = $batch->filter(fn (Tree $tree) => $tree->getAttribute('export_inspection_id') !== null);
        if ($selected->isEmpty()) {
            return collect();
        }
        $fields = self::INSPECTION_FIELDS;
        $relations = ['maturityState:id,name', 'conditions:id,code,name,condition_category_id,assessment_type',
            'conditions.category:id,name', 'faunaCategories' => fn ($fauna) => $fauna->select('fauna_categories.id', 'code', 'name')
                ->orderBy('code')->orderBy('fauna_categories.id')];
        if (!$selection->isPublic()) {
            $fields = array_merge($fields, self::PRIVATE_INSPECTION_FIELDS);
            $relations[] = 'classificationRuleVersion:id,code';
            $relations[] = 'riskEvaluation.methodology';
            $relations[] = 'carbonEstimate';
        }
        if ($riskOnly) {
            $fields = ['id', 'organization_id', 'tree_id', 'inspection_date', 'lock_version', 'inventory_tree_id'];
            $relations = ['riskEvaluation.methodology', 'riskEvaluation.confirmer',
                'inventoryTree:id,organization_id,tree_id,inventory_id', 'inventoryTree.inventory:id,organization_id,code,name'];
        }
        $query = TreeInspection::query()->select($fields)->with($relations)
            ->where(function (Builder $query) use ($selected): void {
                foreach ($selected->groupBy('organization_id') as $organizationId => $trees) {
                    $query->orWhere(fn (Builder $tenant) => $tenant->where('organization_id', $organizationId)
                        ->whereIn('id', $trees->pluck('export_inspection_id')->all()));
                }
            });
        if ($selection->isPublic()) {
            $this->publication->inspection($query);
        }

        return $query->get()->keyBy('id');
    }

    private function evidences(ReportSelectionDTO $selection, Collection $inspections): Collection
    {
        if ($inspections->isEmpty()) {
            return collect();
        }
        $query = $selection->isPublic() ? $this->publicTrees->evidenceQuery() : Evidence::query()->with('evidenceType:id,code,name');

        return $query->select(['evidences.id', 'evidences.organization_id', 'evidences.tree_inspection_id',
            'evidences.evidence_type_id', 'evidences.public_id'])
            ->where(function (Builder $query) use ($inspections): void {
                foreach ($inspections->groupBy('organization_id') as $organizationId => $visits) {
                    $query->orWhere(fn (Builder $tenant) => $tenant->where('evidences.organization_id', $organizationId)
                        ->whereIn('evidences.tree_inspection_id', $visits->pluck('id')->all()));
                }
            })
            ->whereHas('evidenceType', fn (Builder $type) => $type->whereIn('code', ['EVD_FAUNA', 'EVD_WHOLE_TREE']))
            ->orderByDesc('evidences.captured_at')->orderByDesc('evidences.created_at')->orderByDesc('evidences.id')
            ->get()->groupBy('tree_inspection_id');
    }
}
