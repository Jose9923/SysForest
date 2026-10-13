<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\TreeReadScope;
use App\Models as M;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Opciones paginadas y etiquetas históricas; el alcance siempre antecede a los filtros del navegador. */
class MapOptionRepository
{
    public const FIELDS = [
        'department_id' => 'departments', 'municipality_id' => 'municipalities',
        'organization_id' => 'organizations', 'inventory_id' => 'inventories',
        'species_id' => 'species', 'family_id' => 'families', 'common_name_id' => 'common_names', 'placement_id' => 'placements',
        'growth_habit_id' => 'growth_habits', 'establishment_origin_id' => 'establishment_origins',
        'biogeographic_origin_id' => 'biogeographic_origins', 'maturity_state_id' => 'maturity_states',
        'physical_condition_ids' => 'physical_conditions', 'phytosanitary_condition_ids' => 'phytosanitary_conditions',
        'maintenance_type_id' => 'maintenance_types',
    ];

    private const CATALOGS = [
        'species' => M\Species::class, 'families' => M\Family::class, 'common_names' => M\SpeciesCommonName::class,
        'placements' => M\Placement::class,
        'growth_habits' => M\GrowthHabit::class, 'establishment_origins' => M\EstablishmentOrigin::class,
        'biogeographic_origins' => M\BiogeographicOrigin::class, 'maturity_states' => M\MaturityState::class,
        'physical_conditions' => M\Condition::class, 'phytosanitary_conditions' => M\Condition::class,
        'maintenance_types' => M\MaintenanceType::class,
    ];

    public function __construct(private MapScopeRepository $scopes, private PublicVisibilityRepository $visibility)
    {
    }

    public function query(string $kind, TreeReadScope $scope, array $filters): Builder
    {
        if ($kind === 'departments') {
            $query = M\Department::query()->where('active', true)
                ->whereHas('country', fn (Builder $country) => $country->where('active', true));
            if ($scope->kind === 'department') {
                $query->whereIn('id', $scope->departmentIds);
            } elseif (in_array($scope->kind, ['organization', 'published'], true)) {
                $query->whereHas('municipalities', fn (Builder $municipalities) => $municipalities
                    ->whereIn('id', $this->scopes->organizations($scope)->select('municipality_id')));
            }

            return $query;
        }
        if ($kind === 'municipalities') {
            $query = M\Municipality::query()->where('active', true)
                ->whereIn('department_id', $this->query('departments', $scope, $filters)->select('departments.id'));
            if (!empty($filters['department_id'])) {
                $query->where('department_id', $filters['department_id']);
            }
            if (in_array($scope->kind, ['organization', 'published'], true)) {
                $query->whereIn('id', $this->scopes->organizations($scope)->select('municipality_id'));
            }

            return $query;
        }
        if ($kind === 'organizations') {
            $query = $this->scopes->organizations($scope);
            if (!empty($filters['municipality_id'])) {
                $query->where('municipality_id', $filters['municipality_id']);
            }
            if (!empty($filters['department_id'])) {
                $query->whereHas('municipality', fn (Builder $municipality) => $municipality
                    ->where('department_id', $filters['department_id']));
            }

            return $query;
        }
        if ($kind === 'inventories') {
            $query = M\Inventory::query()->whereIn('organization_id', $this->query('organizations', $scope, $filters)->select('organizations.id'));
            if ($scope->kind === 'published') {
                $this->visibility->inventory($query);
            }
            if (!empty($filters['organization_id'])) {
                $query->where('organization_id', $filters['organization_id']);
            }

            return $query;
        }
        abort_unless(isset(self::CATALOGS[$kind]), 404);
        $model = self::CATALOGS[$kind];
        $query = $model::query();
        if ($kind === 'species') {
            $query->with(['commonNames' => fn ($names) => $names->orderByDesc('is_primary')->orderBy('name')->orderBy('id')]);
            if (!empty($filters['family_id'])) {
                $query->where('family_id', $filters['family_id']);
            }
        } elseif ($kind === 'common_names') {
            $query->with('species:id,scientific_name');
            if (!empty($filters['species_id'])) {
                $query->where('species_id', $filters['species_id']);
            }
            if (!empty($filters['family_id'])) {
                $query->whereHas('species', fn (Builder $species) => $species->where('family_id', $filters['family_id']));
            }
        }
        if (in_array($kind, ['physical_conditions', 'phytosanitary_conditions'], true)) {
            $query->where('assessment_type', $kind === 'physical_conditions' ? 'physical_mechanical' : 'phytosanitary')
                ->with('category:id,name');
        }

        return $query;
    }

    public function referenceExists(string $kind, TreeReadScope $scope, array $filters, string $id): bool
    {
        return $this->query($kind, $scope, $filters)->whereKey($id)->exists();
    }

    public function options(string $kind, TreeReadScope $scope, array $filters, string $term, int $page): array
    {
        $query = $this->query($kind, $scope, $filters);
        $name = in_array($kind, ['species', 'families'], true) ? 'scientific_name' : 'name';
        $this->search($query, $kind, $term);
        $records = $query->orderBy($name)->orderBy('id')->offset(($page - 1) * 30)->limit(31)->get();

        return ['results' => $records->take(30)->map(fn ($record) => $this->label($record, $kind))->values()->all(),
            'pagination' => ['more' => $records->count() > 30]];
    }

    public function selected(TreeReadScope $scope, array $filters): array
    {
        $options = [];
        foreach (self::FIELDS as $field => $kind) {
            if (!isset($filters[$field]) || $filters[$field] === '' || $filters[$field] === []) {
                continue;
            }
            $ids = is_array($filters[$field]) ? $filters[$field] : [$filters[$field]];
            // La cascada limita opciones nuevas, no oculta criterios históricos o incompatibles ya seleccionados.
            $criteria = in_array($kind, ['species', 'common_names'], true)
                ? array_diff_key($filters, array_flip(['family_id', 'species_id'])) : $filters;
            $options[$field] = $this->query($kind, $scope, $criteria)->whereKey($ids)->get()
                ->map(fn ($record) => $this->label($record, $kind))->values()->all();
        }

        return $options;
    }

    /** Devuelve únicamente geometrías reales seleccionadas, con tamaño limitado para la interfaz. */
    public function territories(TreeReadScope $scope, array $filters): array
    {
        $filters = $this->territorialFilters($scope, $filters);
        $features = [];
        $meta = ['omitted_complex' => 0, 'omitted_invalid' => 0, 'truncated' => false];
        foreach (['department_id' => 'departments', 'municipality_id' => 'municipalities'] as $field => $kind) {
            $ids = isset($filters[$field]) ? [$filters[$field]]
                : ($field === 'department_id' && $scope->kind === 'department' ? $scope->departmentIds : []);
            if ($ids === []) {
                continue;
            }
            $query = $this->query($kind, $scope, $filters)->whereKey($ids)->whereNotNull('geometry');
            // Los límites de transporte no se confunden con ausencia de cartografía oficial.
            $counts = (clone $query)->selectRaw('COUNT(*) FILTER (WHERE NOT ST_IsValid(geometry)) AS invalid, '
                .'COUNT(*) FILTER (WHERE ST_IsValid(geometry) AND ST_NPoints(geometry) > 20000) AS complex, '
                .'COUNT(*) FILTER (WHERE ST_IsValid(geometry) AND ST_NPoints(geometry) <= 20000) AS eligible')->first();
            $meta['omitted_invalid'] += (int) $counts->invalid;
            $meta['omitted_complex'] += (int) $counts->complex;
            $meta['truncated'] = $meta['truncated'] || (int) $counts->eligible > 20;
            $rows = $query->whereRaw('ST_IsValid(geometry)')->whereRaw('ST_NPoints(geometry) <= ?', [20000])
                ->select('id', 'name')->selectRaw('ST_AsGeoJSON(geometry) AS geojson')
                ->orderBy('name')->orderBy('id')->limit(20)->get();
            foreach ($rows as $row) {
                $features[] = ['type' => 'Feature', 'geometry' => json_decode($row->geojson, true, 512, JSON_THROW_ON_ERROR),
                    'properties' => ['name' => $row->name, 'kind' => $kind]];
            }
        }

        return ['type' => 'FeatureCollection', 'features' => $features, 'meta' => $meta];
    }

    /** Un alcance sin árboles puede encuadrar su territorio real; no se deducen coordenadas de nombres. */
    public function territoryExtent(TreeReadScope $scope, array $filters): ?array
    {
        $filters = $this->territorialFilters($scope, $filters);
        $kind = !empty($filters['municipality_id']) ? 'municipalities' : 'departments';
        $query = $this->query($kind, $scope, $filters)->whereNotNull('geometry')->whereRaw('ST_IsValid(geometry)');
        if (!empty($filters['municipality_id'])) {
            $query->whereKey($filters['municipality_id']);
        } elseif (!empty($filters['department_id'])) {
            $query->whereKey($filters['department_id']);
        }
        $extent = DB::query()->fromSub($query->select('geometry')->toBase(), 'territories')
            ->selectRaw('ST_Extent(geometry) AS bounds');
        $row = DB::query()->fromSub($extent, 'territory_extent')
            ->selectRaw('ST_XMin(bounds) AS west, ST_YMin(bounds) AS south, ST_XMax(bounds) AS east, ST_YMax(bounds) AS north')->first();

        if ($row->west === null && $kind === 'municipalities' && !empty($filters['department_id'])) {
            unset($filters['municipality_id'], $filters['organization_id'], $filters['inventory_id']);
            $departmentScope = $scope->kind === 'organization' ? \App\DTOs\TreeReadScope::department([$filters['department_id']]) : $scope;

            return $this->territoryExtent($departmentScope, $filters);
        }

        return $row->west === null ? null
            : [[(float) $row->south, (float) $row->west], [(float) $row->north, (float) $row->east]];
    }

    /** Resuelve el territorio del propietario autorizado antes de usar geometrías como alternativa. */
    private function territorialFilters(TreeReadScope $scope, array $filters): array
    {
        $organizationId = $filters['organization_id'] ?? $scope->organizationId;
        if ($organizationId === null && !empty($filters['inventory_id'])) {
            $organizationId = $this->query('inventories', $scope, $filters)->whereKey($filters['inventory_id'])->value('organization_id');
        }
        if ($organizationId !== null && empty($filters['municipality_id'])) {
            $municipalityId = $this->query('organizations', $scope, $filters)->whereKey($organizationId)->value('municipality_id');
            if ($municipalityId !== null) {
                $filters['municipality_id'] = $municipalityId;
            }
        }
        if (!empty($filters['municipality_id']) && empty($filters['department_id'])) {
            $filters['department_id'] = $this->query('municipalities', $scope, $filters)
                ->whereKey($filters['municipality_id'])->value('department_id');
        }

        return $filters;
    }

    /** Los alias son alternativas de búsqueda; EXISTS evita multiplicar especies que coinciden varias veces. */
    public function search(Builder $query, string $kind, string $term): void
    {
        if ($term === '') {
            return;
        }
        $name = in_array($kind, ['species', 'families'], true) ? 'scientific_name' : 'name';
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $query->where(function (Builder $matches) use ($kind, $name, $pattern): void {
            $matches->where($name, 'ilike', $pattern);
            if ($kind === 'species') {
                $matches->orWhereHas('commonNames', fn (Builder $names) => $names->where('name', 'ilike', $pattern));
            } elseif ($kind === 'common_names') {
                $matches->orWhereHas('species', fn (Builder $species) => $species->where('scientific_name', 'ilike', $pattern));
            }
        });
    }

    /** Texto compartido por opciones privadas y públicas; la identidad se proyecta por separado. */
    public function optionText($record, string $kind): string
    {
        $text = in_array($kind, ['species', 'families'], true) ? $record->scientific_name : $record->name;
        if ($kind === 'common_names') {
            $text .= ' · '.$record->getRelation('species')->scientific_name;
        } elseif ($kind === 'species') {
            $names = $record->getRelation('commonNames')->pluck('name')->unique()->implode(', ');
            if ($names !== '') {
                $text .= ' · '.$names;
            }
        } elseif (in_array($kind, ['physical_conditions', 'phytosanitary_conditions'], true)) {
            $text = $record->getRelation('category')->name.' · '.$text;
        }
        if (isset($record->active) && !$record->active) {
            $text .= ' (inactivo)';
        }

        return $text;
    }

    private function label($record, string $kind): array
    {
        return ['id' => (string) $record->id, 'text' => $this->optionText($record, $kind)];
    }
}
