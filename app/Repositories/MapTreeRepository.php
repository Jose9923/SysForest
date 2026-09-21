<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\MapTreeFilterDTO;
use App\DTOs\TreeReadScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** PostGIS resuelve encuadres, distancias, extensiones y agregaciones sobre el mismo motor forestal. */
class MapTreeRepository
{
    public function __construct(private TreeQueryRepository $trees)
    {
    }

    public function query(TreeReadScope $scope, MapTreeFilterDTO $filters, bool $viewport = false): Builder
    {
        $query = $this->trees->query($scope, $filters->trees)->whereNull('trees.deleted_at');
        if ($filters->radius !== null) {
            $query->whereRaw(
                'ST_DWithin(trees.geometry::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                array_map($this->number(...), [$filters->radius['longitude'], $filters->radius['latitude'], $filters->radius['meters']])
            );
        }
        if ($viewport && $filters->bbox !== null) {
            $box = $filters->bbox;
            // ST_Intersects incluye el borde y permite que el planificador utilice trees_geometry_gist.
            $query->whereRaw(
                'ST_Intersects(trees.geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                array_map($this->number(...), [$box['west'], $box['south'], $box['east'], $box['north']])
            );
        }

        return $query;
    }

    public function summary(TreeReadScope $scope, MapTreeFilterDTO $filters): array
    {
        $query = $this->query($scope, $filters);
        // Retira trees.*: una agregación nunca debe hidratar la identidad de un individuo arbitrario.
        $row = $query->toBase()->select([
            DB::raw('COUNT(*) AS total'), DB::raw('COUNT(trees.geometry) AS georeferenced'),
        ])->first();

        return ['total' => (int) $row->total, 'georeferenced' => (int) $row->georeferenced,
            'without_geometry' => (int) $row->total - (int) $row->georeferenced];
    }

    public function countInView(TreeReadScope $scope, MapTreeFilterDTO $filters): int
    {
        return $this->query($scope, $filters, true)->whereNotNull('trees.geometry')->count();
    }

    public function points(TreeReadScope $scope, MapTreeFilterDTO $filters, int $limit): Collection
    {
        return $this->query($scope, $filters, true)->whereNotNull('trees.geometry')
            ->select(['trees.id', 'trees.code', 'trees.species_id', 'trees.placement_id', 'trees.current_status'])
            ->selectRaw('ST_X(trees.geometry) AS longitude, ST_Y(trees.geometry) AS latitude, latest_inspection.inspection_date AS latest_inspection_date')
            ->with(['species:id,scientific_name',
                'species.commonNames' => fn ($names) => $names->select('id', 'species_id', 'name')->orderBy('name')->orderBy('id'),
                'placement:id,name'])
            ->orderBy('trees.code')->orderBy('trees.id')->limit($limit)->get();
    }

    /** Búsqueda para localizar dentro del conjunto autorizado, sin descargar un catálogo de individuos. */
    public function search(TreeReadScope $scope, MapTreeFilterDTO $filters, string $term, int $page): Collection
    {
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $this->query($scope, $filters)
            ->where(fn (Builder $query) => $query->where('trees.code', 'ilike', $term)
                ->orWhereHas('species', fn (Builder $species) => $species->where('scientific_name', 'ilike', $term)
                    ->orWhereHas('commonNames', fn (Builder $names) => $names->where('name', 'ilike', $term))))
            ->select(['trees.id', 'trees.code', 'trees.species_id', 'trees.placement_id', 'trees.current_status'])
            ->selectRaw('ST_X(trees.geometry) AS longitude, ST_Y(trees.geometry) AS latitude, NULL AS latest_inspection_date')
            ->with(['species:id,scientific_name',
                'species.commonNames' => fn ($names) => $names->select('id', 'species_id', 'name')->orderBy('name')->orderBy('id'),
                'placement:id,name'])
            ->orderBy('trees.code')->orderBy('trees.id')->offset(($page - 1) * 20)->limit(21)->get();
    }

    /**
     * La malla depende del zoom y se ensancha hasta ocupar como máximo 65×65 celdas del viewport.
     * COUNT preserva la cantidad completa; AVG localiza su centro sin materializar colecciones de geometrías.
     */
    public function cells(TreeReadScope $scope, MapTreeFilterDTO $filters): Collection
    {
        $box = $filters->bbox;
        $axis = (int) config('maps.grid_cells_per_axis', 64);
        $step = max(
            360 / (2 ** $filters->zoom * 8),
            ($box['east'] - $box['west']) / $axis,
            ($box['north'] - $box['south']) / $axis
        );
        $query = $this->query($scope, $filters, true)->whereNotNull('trees.geometry')->select([])
            ->selectRaw(
                'FLOOR((ST_X(trees.geometry) - ?) / ?) AS cell_x, FLOOR((ST_Y(trees.geometry) - ?) / ?) AS cell_y',
                array_map($this->number(...), [$box['west'], $step, $box['south'], $step])
            )
            ->addSelect(DB::raw('ST_X(trees.geometry) AS longitude'), DB::raw('ST_Y(trees.geometry) AS latitude'));
        // La proyección espacial evita cargar atributos y relaciones individuales para representar densidad.
        return DB::query()->fromSub($query->toBase(), 'filtered_points')
            ->selectRaw('COUNT(*) AS count, AVG(longitude) AS longitude, AVG(latitude) AS latitude, '
                .'MIN(longitude) AS west, MAX(longitude) AS east, MIN(latitude) AS south, MAX(latitude) AS north')
            ->groupBy('cell_x', 'cell_y')->orderBy('cell_x')->orderBy('cell_y')->get();
    }

    /** Conserva el double completo al enlazarlo: la precisión de presentación de PHP no debe mover bordes ni centros. */
    private function number(float $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public function extent(TreeReadScope $scope, MapTreeFilterDTO $filters): ?array
    {
        $points = $this->query($scope, $filters)->whereNotNull('trees.geometry')->select('trees.geometry');
        $extent = DB::query()->fromSub($points->toBase(), 'filtered_points')->selectRaw('ST_Extent(geometry) AS bounds');
        $row = DB::query()->fromSub($extent, 'filtered_extent')
            ->selectRaw('ST_XMin(bounds) AS west, ST_YMin(bounds) AS south, ST_XMax(bounds) AS east, ST_YMax(bounds) AS north')->first();

        return $row->west === null ? null
            : [[(float) $row->south, (float) $row->west], [(float) $row->north, (float) $row->east]];
    }
}
