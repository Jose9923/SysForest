<?php

declare(strict_types=1);

namespace App\Repositories\Catalogs;

use App\Enums\Catalogs\CatalogCodeKind;
use App\Support\Identifiers\RecordIdentifier;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Códigos nacionales y resolución de referencias de origen sin conservar su texto. */
class CatalogCodeRepository
{
    public function resolveId(CatalogCodeKind $kind, string $code): ?int
    {
        if (!$this->validSourceKey($code)) {
            return null;
        }

        return $this->resolveSourceId($kind, $code);
    }

    public function resolveSourceId(CatalogCodeKind $kind, string $sourceKey, ?string $legacyHash = null): ?int
    {
        return $this->resolveSources($kind, [$sourceKey => $legacyHash])[$sourceKey] ?? null;
    }

    /** @param array<string, string|null> $sources Clave de origen => huella anterior opcional.
     *  @return array<string, int> Solo las referencias encontradas, sin inferir coincidencias por nombre.
     */
    public function resolveSources(CatalogCodeKind $kind, array $sources): array
    {
        if ($sources === []) {
            return [];
        }
        $hashesByKey = [];
        $legacyHashes = [];
        foreach ($sources as $sourceKey => $legacyHash) {
            if (!is_string($sourceKey) || !$this->validSourceKey($sourceKey)
                || ($legacyHash !== null && (!is_string($legacyHash) || !$this->validHash($legacyHash)))) {
                throw new LogicException('La referencia de origen o su huella no es válida.');
            }
            $hashesByKey[$sourceKey] = array_values(array_unique(array_filter([
                hash('sha256', $sourceKey), $legacyHash,
            ], fn ($hash): bool => $hash !== null)));
            if ($legacyHash !== null) {
                $legacyHashes[] = $legacyHash;
            }
        }
        $hashes = array_values(array_unique(array_merge(...array_values($hashesByKey))));
        $current = DB::table($kind->table())->where(function ($query) use ($sources, $legacyHashes): void {
            $query->whereIn('code', array_keys($sources));
            if ($legacyHashes !== []) {
                // Resuelve también antecedentes que todavía no se han normalizado.
                $query->orWhereIn(DB::raw("encode(sha256(convert_to(code, 'UTF8')), 'hex')"), array_values(array_unique($legacyHashes)));
            }
        })->get(['id', 'code']);
        $currentIds = [];
        foreach ($current as $record) {
            $currentIds[hash('sha256', $record->code)][] = (int) $record->id;
        }
        $aliases = DB::table('catalog_code_aliases')->where('catalog', $kind->value)
            ->whereIn('source_hash', $hashes)->pluck($kind->referenceColumn(), 'source_hash');
        $resolved = [];
        foreach ($hashesByKey as $sourceKey => $sourceHashes) {
            $ids = [];
            foreach ($sourceHashes as $hash) {
                $ids = [...$ids, ...($currentIds[$hash] ?? [])];
                if ($aliases->has($hash)) {
                    $ids[] = (int) $aliases->get($hash);
                }
            }
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1) {
                throw new LogicException('La referencia de origen corresponde a varias identidades; requiere revisión.');
            }
            if ($ids !== []) {
                $resolved[$sourceKey] = $ids[0];
            }
        }

        return $resolved;
    }

    /** Serializa resolución, reserva y registro de referencias en la transacción de escritura. */
    public function lockCatalog(CatalogCodeKind $kind): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Bloquear códigos de catálogo requiere una transacción.');
        }
        if (!DB::table('catalog_code_sequences')->where('catalog', $kind->value)->lockForUpdate()->first()) {
            throw new LogicException('Falta el contador de catálogo; aplica primero la migración correspondiente.');
        }
    }

    /** Registra ambas huellas sin reasignar referencias ni modificar el catálogo. */
    public function registerAlias(CatalogCodeKind $kind, string $sourceKey, int $id, ?string $legacyHash = null): void
    {
        if (!RecordIdentifier::isValid($id) || !$this->validSourceKey($sourceKey)
            || ($legacyHash !== null && !$this->validHash($legacyHash))) {
            throw new LogicException('La referencia de origen, su huella o su identidad no es válida.');
        }
        $this->lockCatalog($kind);
        $record = DB::table($kind->table())->where('id', $id)->first(['id', 'code']);
        if ($record === null) {
            throw new LogicException('La referencia debe apuntar a un registro existente del mismo catálogo.');
        }
        $resolved = $this->resolveSourceId($kind, $sourceKey, $legacyHash);
        if ($resolved !== null && $resolved !== $id) {
            throw new LogicException('La referencia de origen ya identifica otro registro del catálogo.');
        }
        if ($record->code === $sourceKey && $legacyHash === null) {
            return;
        }
        $hashes = array_values(array_unique(array_filter([
            hash('sha256', $sourceKey), $legacyHash,
        ], fn ($hash): bool => $hash !== null)));
        $existing = DB::table('catalog_code_aliases')->where('catalog', $kind->value)
            ->whereIn('source_hash', $hashes)->pluck($kind->referenceColumn(), 'source_hash');
        foreach ($hashes as $hash) {
            if ($existing->has($hash)) {
                if ((int) $existing->get($hash) !== $id) {
                    throw new LogicException('Una huella de origen ya identifica otro registro del catálogo.');
                }
                continue;
            }
            DB::table('catalog_code_aliases')->insert([
                'catalog' => $kind->value, 'source_hash' => $hash, $kind->referenceColumn() => $id,
            ]);
        }
    }

    /** La reserva se confirma con el registro y nunca reutiliza una referencia histórica. */
    public function nextCode(CatalogCodeKind $kind): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La reserva de un código de catálogo requiere una transacción.');
        }
        do {
            $row = DB::selectOne(
                'INSERT INTO catalog_code_sequences (catalog, value) VALUES (?, 1)
                 ON CONFLICT (catalog) DO UPDATE SET value = catalog_code_sequences.value + 1
                 WHERE catalog_code_sequences.value < 9223372036854775807 RETURNING value',
                [$kind->value],
            );
            if ($row === null) {
                throw new LogicException('El consecutivo del catálogo alcanzó su límite.');
            }
            $code = $kind->prefix().str_pad((string) $row->value, 6, '0', STR_PAD_LEFT);
        } while ($this->resolveId($kind, $code) !== null);

        return $code;
    }

    private function validSourceKey(string $sourceKey): bool
    {
        return preg_match('/\A[A-Z][A-Z0-9_]{1,79}\z/D', $sourceKey) === 1;
    }

    private function validHash(string $hash): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1;
    }
}
