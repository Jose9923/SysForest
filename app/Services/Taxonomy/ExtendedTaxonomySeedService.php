<?php

declare(strict_types=1);

namespace App\Services\Taxonomy;

use App\Catalogs\CatalogRegistry;
use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Repositories\Catalogs\CatalogRepository;
use App\Repositories\Taxonomy\ExtendedTaxonomySeedRepository;
use App\Repositories\Taxonomy\TaxonomyRepository;
use App\Repositories\Catalogs\CatalogCodeRepository;
use App\Enums\Catalogs\CatalogCodeKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Siembra nacional sin actor, tenant, inventario ni dependencia del paquete privado de importación. */
class ExtendedTaxonomySeedService
{
    private const SOURCE_SHA256 = '54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63';

    private const MANIFEST_SHA256 = 'ded816dd91cb6dc68214b565678a9aef7cc7077cdbc41ba8ba62be47a60f84c0';

    public function __construct(
        private ExtendedTaxonomySeedRepository $references,
        private TaxonomyRepository $taxonomy,
        private CatalogRepository $catalogs,
        private CatalogRegistry $registry,
        private CatalogCodeRepository $codes,
    ) {
    }

    /**
     * Agrega únicamente ausentes. Una incompatibilidad revierte todas las altas de esta ampliación.
     * Conserva nombres, padres, actividad, orden, principal, versiones y descripciones existentes.
     *
     * @return array{families: int, species: int, common_names: int, placements: int}
     */
    public function seed(): array
    {
        $source = file_get_contents(database_path('seeders/data/reference-catalogs-v2.json'));
        if ($source === false || !hash_equals(self::MANIFEST_SHA256, hash('sha256', $source))) {
            throw new LogicException('El manifiesto de catálogos ampliados cambió; requiere revisión.');
        }
        $manifest = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['source']['sha256'] ?? null) !== self::SOURCE_SHA256) {
            throw new LogicException('La procedencia de los catálogos ampliados no coincide con la fuente revisada.');
        }

        return DB::transaction(function () use ($manifest): array {
            // Un orden común evita snapshots de alias anteriores a otra siembra concurrente.
            foreach (CatalogCodeKind::cases() as $catalog) {
                $this->codes->lockCatalog($catalog);
            }
            $this->validateReferences($manifest['references']);
            $created = array_fill_keys(['families', 'species', 'common_names', 'placements'], 0);
            $families = $this->references->taxonomy(TaxonomyCatalog::Families);
            $species = $this->references->taxonomy(TaxonomyCatalog::Species);
            $commonNames = $this->references->taxonomy(TaxonomyCatalog::CommonNames);
            $familyAliases = $this->codes->resolveSources(CatalogCodeKind::Families, $this->sourceKeys($manifest['families']));
            $speciesAliases = $this->codes->resolveSources(CatalogCodeKind::Species, $this->sourceKeys($manifest['species']));
            $commonAliases = $this->codes->resolveSources(CatalogCodeKind::CommonNames, $this->sourceKeys(array_merge(...array_column($manifest['species'], 'common_names'))));
            $parents = [];
            $description = 'Fuente: inventario forestal de referencia. SHA-256: '.self::SOURCE_SHA256
                .'. Etiqueta transcrita; no implica validación nomenclatural ni resolución de sinónimos.';

            foreach ($manifest['families'] as $row) {
                $family = $this->existing($families, $row, 'scientific_name', aliases: $familyAliases);
                if ($family === null) {
                    $family = $this->taxonomy->seed(TaxonomyCatalog::Families, $row['code'], [
                        'scientific_name' => $row['scientific_name'],
                        'description' => $description, 'active' => true, 'sort_order' => 0,
                    ], $row['legacy_code_hash'] ?? null);
                    $families->push($family);
                    $familyAliases[$row['code']] = (int) $family->id;
                    $created['families']++;
                }
                $this->codes->registerAlias(CatalogCodeKind::Families, $row['code'], (int) $family->id, $row['legacy_code_hash'] ?? null);
                $parents[$row['code']] = $family;
            }
            foreach ($manifest['species'] as $row) {
                $family = $parents[$row['family_code']]
                    ?? throw new LogicException('La familia de '.$row['code'].' no está en el manifiesto revisado.');
                $record = $this->existing($species, $row, 'scientific_name', ['family_id' => $family->id], aliases: $speciesAliases);
                if ($record === null) {
                    $record = $this->taxonomy->seed(TaxonomyCatalog::Species, $row['code'], [
                        'scientific_name' => $row['scientific_name'], 'family_id' => $family->id,
                        'description' => $description, 'active' => true, 'sort_order' => 0,
                    ], $row['legacy_code_hash'] ?? null);
                    $species->push($record);
                    $speciesAliases[$row['code']] = (int) $record->id;
                    $created['species']++;
                }
                $this->codes->registerAlias(CatalogCodeKind::Species, $row['code'], (int) $record->id, $row['legacy_code_hash'] ?? null);
                foreach ($row['common_names'] as $commonRow) {
                    $existingCommon = $this->existing($commonNames, $commonRow, 'name', ['species_id' => $record->id], nameWithinParent: true, aliases: $commonAliases);
                    if ($existingCommon !== null) {
                        $this->codes->registerAlias(CatalogCodeKind::CommonNames, $commonRow['code'], (int) $existingCommon->id, $commonRow['legacy_code_hash'] ?? null);
                        continue;
                    }
                    $primaryExists = $commonNames->contains(fn (Model $common): bool => $common->species_id === $record->id && $common->is_primary);
                    $common = $this->taxonomy->seed(TaxonomyCatalog::CommonNames, $commonRow['code'], [
                        'name' => $commonRow['name'], 'species_id' => $record->id,
                        'is_primary' => !$primaryExists, 'description' => $description, 'active' => true, 'sort_order' => 0,
                    ], $commonRow['legacy_code_hash'] ?? null);
                    $commonNames->push($common);
                    $commonAliases[$commonRow['code']] = (int) $common->id;
                    $created['common_names']++;
                }
            }
            $catalog = $this->registry->find('placements');
            $placements = $this->references->catalog($catalog);
            $placementAliases = $this->codes->resolveSources(CatalogCodeKind::Placements, $this->sourceKeys($manifest['placements']));
            foreach ($manifest['placements'] as $row) {
                $existingPlacement = $this->existing($placements, $row, 'name', accentInsensitive: true, aliases: $placementAliases);
                if ($existingPlacement !== null) {
                    $this->codes->registerAlias(CatalogCodeKind::Placements, $row['code'], (int) $existingPlacement->id, $row['legacy_code_hash'] ?? null);
                    continue;
                }
                $record = $this->catalogs->create($catalog, [
                    'code' => $this->codes->nextCode(CatalogCodeKind::Placements), 'name' => $row['name'], 'active' => true, 'sort_order' => 0,
                    'description' => 'Emplazamiento transcrito del inventario forestal de referencia; SHA-256: '.self::SOURCE_SHA256.'.',
                ]);
                $this->codes->registerAlias(CatalogCodeKind::Placements, $row['code'], (int) $record->id, $row['legacy_code_hash'] ?? null);
                $placementAliases[$row['code']] = (int) $record->id;
                $placements->push($record);
                $created['placements']++;
            }

            return $created;
        }, 3);
    }

    /** @return array<string, string|null> */
    private function sourceKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['code']] = $row['legacy_code_hash'] ?? null;
        }

        return $keys;
    }

    private function validateReferences(array $references): void
    {
        foreach (['growth_habits' => 'growth-habits', 'establishment_origins' => 'establishment-origins', 'biogeographic_origins' => 'biogeographic-origins'] as $key => $catalog) {
            $records = $this->references->catalog($this->registry->find($catalog));
            foreach ($references[$key] as $row) {
                $this->existing($records, $row + ['expected_existing' => true], 'name');
            }
        }
    }

    /** Los nombres científicos son únicos globalmente; los comunes solo dentro de su especie. */
    private function existing(
        Collection $records,
        array $row,
        string $nameField,
        array $parent = [],
        bool $accentInsensitive = false,
        bool $nameWithinParent = false,
        array $aliases = [],
    ): ?Model {
        $sameName = fn (Model $candidate): bool => $this->normalized($candidate->getAttribute($nameField), $accentInsensitive)
            === $this->normalized($row[$nameField], $accentInsensitive);
        $sameParent = function (Model $candidate) use ($parent): bool {
            foreach ($parent as $field => $value) {
                if ($candidate->getAttribute($field) !== $value) {
                    return false;
                }
            }

            return true;
        };
        $record = $records->firstWhere('code', $row['code']);
        if ($record === null && isset($aliases[$row['code']])) {
            $record = $records->find($aliases[$row['code']]);
        }
        if ($record !== null) {
            if (!$record->active || !$sameName($record) || !$sameParent($record)) {
                throw new LogicException('La referencia '.$row['code'].' cambió o está inactiva; se conserva y requiere revisión.');
            }

            return $record;
        }
        if (($row['expected_existing'] ?? false)
            || $records->contains(fn (Model $candidate): bool => $sameName($candidate) && (!$nameWithinParent || $sameParent($candidate)))) {
            throw new LogicException('La referencia '.$row['code'].' falta o coincide con otro código; no se duplica. Ejecuta antes los catálogos base y revisa los conflictos.');
        }

        return null;
    }

    private function normalized(string $value, bool $accentInsensitive = false): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));

        return $accentInsensitive ? Str::ascii($normalized) : $normalized;
    }
}
