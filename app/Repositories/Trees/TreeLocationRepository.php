<?php

declare(strict_types=1);

namespace App\Repositories\Trees;

use App\DTOs\Forestry\ParsedCoordinates;
use App\Models\Trees\Tree;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class TreeLocationRepository
{
    private const METADATA_KEYS = ['coordinate_format', 'datum', 'accuracy_m', 'gps_brand', 'gps_model', 'captured_at'];

    /** El bloqueo participa en la transacción del servicio que registra el árbol. */
    public function snapshot(string $organizationId, string $treeId): Tree
    {
        $this->requireTransaction();
        if (! \App\Support\Identifiers\RecordIdentifier::isValid($treeId)) {
            throw (new ModelNotFoundException())->setModel(Tree::class);
        }

        return $this->query($organizationId)->whereKey($treeId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Conserva las geometrías previas directamente en PostGIS. El llamante ya validó
     * permisos, comparó el snapshot bloqueado y abrió la transacción de la operación.
     */
    public function apply(
        Tree $current,
        ?ParsedCoordinates $coordinates,
        array $oldMetadata,
        array $newMetadata,
        User $actor,
        ?string $changeType,
        ?string $reason,
        bool $initial = false,
    ): string {
        $this->requireTransaction();
        $point = $coordinates === null ? 'NULL' : 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';
        $pointBindings = $coordinates === null ? [] : $this->pointBindings($coordinates->latitude, $coordinates->longitude);
        $oldPoint = $initial ? 'NULL' : 'trees.geometry';
        $oldRaw = $initial ? 'NULL' : 'trees.coordinate_raw';
        $changedAt = now();
        $historyExternalId = (string) Str::uuid();
        $bindings = array_merge(
            [$historyExternalId],
            $pointBindings,
            [
                $coordinates?->raw,
                json_encode($initial ? (object) [] : $oldMetadata, JSON_THROW_ON_ERROR),
                json_encode($newMetadata, JSON_THROW_ON_ERROR),
                $changeType, $reason, $actor->id, $changedAt,
                $current->organization_id, $current->id,
            ],
        );
        $history = DB::selectOne(
            'INSERT INTO tree_location_histories
                (external_id, organization_id, tree_id, old_geometry, new_geometry,
                 old_coordinate_raw, new_coordinate_raw, old_metadata, new_metadata,
                 change_type, reason, changed_by, changed_at)
             SELECT ?, trees.organization_id, trees.id, '.$oldPoint.', '.$point.',
                    '.$oldRaw.', ?, CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, ?, ?
             FROM trees WHERE organization_id = ? AND id = ? AND deleted_at IS NULL RETURNING id',
            $bindings,
        );

        $values = array_map(fn (string $key) => $newMetadata[$key] ?? null, self::METADATA_KEYS);
        DB::update(
            'UPDATE trees SET geometry = '.$point.',
             coordinate_raw = ?, coordinate_format = ?, datum = ?, accuracy_m = ?,
             gps_brand = ?, gps_model = ?, captured_at = ?, updated_at = ?
             WHERE organization_id = ? AND id = ? AND deleted_at IS NULL',
            array_merge($pointBindings, [$coordinates?->raw], $values, [$changedAt, $current->organization_id, $current->id]),
        );

        if ($history === null) {
            throw new LogicException('No se pudo registrar el historial de ubicación.');
        }

        return (string) $history->id;
    }

    public function withinBoundingBox(
        string $organizationId,
        float $west,
        float $south,
        float $east,
        float $north,
        ?string $inventoryId = null,
    ): Collection {
        $this->validatePoint($south, $west);
        $this->validatePoint($north, $east);
        if ($west > $east || $south > $north) {
            throw ValidationException::withMessages(['bounding_box' => 'Los límites oeste/sur deben ser menores o iguales a este/norte. Divide una ventana que cruce el antimeridiano.']);
        }

        return $this->query($organizationId, $inventoryId)
            ->whereRaw('ST_Intersects(trees.geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))', array_map($this->number(...), [$west, $south, $east, $north]))
            ->orderBy('trees.id')->get();
    }

    public function withinRadius(
        string $organizationId,
        float $latitude,
        float $longitude,
        float $meters,
        ?string $inventoryId = null,
    ): Collection {
        $this->validatePoint($latitude, $longitude);
        if (! is_finite($meters) || $meters < 0) {
            throw ValidationException::withMessages(['radius_m' => 'El radio debe ser un número finito mayor o igual a cero, expresado en metros.']);
        }

        return $this->distanceQuery($organizationId, $latitude, $longitude, $inventoryId)
            ->whereRaw('ST_DWithin(trees.geometry::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', array_merge($this->pointBindings($latitude, $longitude), [$this->number($meters)]))
            ->orderBy('distance_m')->orderBy('trees.id')->get();
    }

    public function nearest(
        string $organizationId,
        float $latitude,
        float $longitude,
        int $limit = 10,
        ?string $inventoryId = null,
    ): Collection {
        $this->validatePoint($latitude, $longitude);
        if ($limit < 1 || $limit > 100) {
            throw ValidationException::withMessages(['limit' => 'Solicita entre 1 y 100 árboles cercanos por consulta.']);
        }

        return $this->distanceQuery($organizationId, $latitude, $longitude, $inventoryId)
            ->orderBy('distance_m')->orderBy('trees.id')->limit($limit)->get();
    }

    private function query(string $organizationId, ?string $inventoryId = null): Builder
    {
        $query = Tree::query()->where('trees.organization_id', $organizationId)
            ->select('trees.*')->selectRaw('ST_Y(trees.geometry) AS latitude, ST_X(trees.geometry) AS longitude');
        if ($inventoryId !== null) {
            if (! \App\Support\Identifiers\RecordIdentifier::isValid($inventoryId)) {
                throw ValidationException::withMessages(['inventory_id' => 'Selecciona un inventario válido.']);
            }
            $query->whereHas('inventories', fn (Builder $inventories) => $inventories
                ->where('inventories.organization_id', $organizationId)->where('inventories.id', $inventoryId));
        }

        return $query;
    }

    private function distanceQuery(string $organizationId, float $latitude, float $longitude, ?string $inventoryId): Builder
    {
        return $this->query($organizationId, $inventoryId)->whereNotNull('trees.geometry')
            ->selectRaw('ST_Distance(trees.geometry::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_m', $this->pointBindings($latitude, $longitude));
    }

    private function pointBindings(float $latitude, float $longitude): array
    {
        return [$this->number($longitude), $this->number($latitude)];
    }

    /** PDO no debe truncar un double mediante la precisión de conversión de float a string. */
    private function number(float $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function validatePoint(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            throw ValidationException::withMessages(['coordinate_raw' => 'La latitud debe estar entre -90 y 90 y la longitud entre -180 y 180, sin valores no finitos.']);
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La ubicación y su historial requieren la transacción del árbol.');
        }
    }
}
