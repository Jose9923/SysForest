<?php

declare(strict_types=1);

namespace App\Services\Taxonomy;

use App\Catalogs\CatalogRegistry;
use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Repositories\Catalogs\CatalogRepository;
use App\Repositories\Taxonomy\ExtendedTaxonomySeedRepository;
use App\Repositories\Taxonomy\TaxonomyRepository;
use App\Repositories\Taxonomy\TaxonomyCodeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Siembra nacional sin actor, tenant, inventario ni dependencia del paquete privado de importación. */
class ExtendedTaxonomySeedService
{
    private const SOURCE_SHA256 = '54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63';

    private const MANIFEST_SHA256 = '06a5ca23c2c22255102b7a873f238ea6e4153f58a239cd234919c2d3394aac7a';

    public function __construct(
        private ExtendedTaxonomySeedRepository $references,
        private TaxonomyRepository $taxonomy,
        private CatalogRepository $catalogs,
        private CatalogRegistry $registry,
        private TaxonomyCodeRepository $codes,
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
        $source = file_get_contents(database_path('seeders/data/restrepo-ornato-catalogs.json'));
        if ($source === false || !hash_equals(self::MANIFEST_SHA256, hash('sha256', $source))) {
            throw new LogicException('El manifiesto de catálogos ampliados cambió; requiere revisión.');
        }
        $manifest = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['source']['sha256'] ?? null) !== self::SOURCE_SHA256) {
            throw new LogicException('La procedencia de los catálogos ampliados no coincide con la fuente revisada.');
        }

        return DB::transaction(function () use ($manifest): array {
            // Un orden común evita snapshots de alias anteriores a otra siembra concurrente.
            foreach (TaxonomyCatalog::cases() as $catalog) {
                $this->codes->lockCatalog($catalog);
            }
            $this->validateReferences($manifest['references']);
            $created = array_fill_keys(['families', 'species', 'common_names', 'placements'], 0);
            $families = $this->references->taxonomy(TaxonomyCatalog::Families);
            $species = $this->references->taxonomy(TaxonomyCatalog::Species);
            $commonNames = $this->references->taxonomy(TaxonomyCatalog::CommonNames);
            $familyAliases = $this->codes->aliasesFor(TaxonomyCatalog::Families, $families->modelKeys());
            $speciesAliases = $this->codes->aliasesFor(TaxonomyCatalog::Species, $species->modelKeys());
            $commonAliases = $this->codes->aliasesFor(TaxonomyCatalog::CommonNames, $commonNames->modelKeys());
            $parents = [];
            $description = 'Fuente: Planilla Plan Ornato Restrepo 2025 Total para riesgos.xlsx. SHA-256: '.self::SOURCE_SHA256
                .'. Etiqueta transcrita; no implica validación nomenclatural ni resolución de sinónimos.';

            foreach ($manifest['families'] as $row) {
                $family = $this->existing($families, $row, 'scientific_name', aliases: $familyAliases);
                if ($family === null) {
                    $family = $this->taxonomy->seed(TaxonomyCatalog::Families, $row['code'], [
                        'scientific_name' => $row['scientific_name'],
                        'description' => $description, 'active' => true, 'sort_order' => 0,
                    ]);
                    $families->push($family);
                    $familyAliases[$row['code']] = (int) $family->id;
                    $created['families']++;
                }
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
                    ]);
                    $species->push($record);
                    $speciesAliases[$row['code']] = (int) $record->id;
                    $created['species']++;
                }
                foreach ($row['common_names'] as $commonRow) {
                    if ($this->existing($commonNames, $commonRow, 'name', ['species_id' => $record->id], nameWithinParent: true, aliases: $commonAliases) !== null) {
                        continue;
                    }
                    $primaryExists = $commonNames->contains(fn (Model $common): bool => $common->species_id === $record->id && $common->is_primary);
                    $common = $this->taxonomy->seed(TaxonomyCatalog::CommonNames, $commonRow['code'], [
                        'name' => $commonRow['name'], 'species_id' => $record->id,
                        'is_primary' => !$primaryExists, 'description' => $description, 'active' => true, 'sort_order' => 0,
                    ]);
                    $commonNames->push($common);
                    $commonAliases[$commonRow['code']] = (int) $common->id;
                    $created['common_names']++;
                }
            }
            $catalog = $this->registry->find('placements');
            $placements = $this->references->catalog($catalog);
            foreach ($manifest['placements'] as $row) {
                if ($this->existing($placements, $row, 'name', accentInsensitive: true) !== null) {
                    continue;
                }
                $record = $this->catalogs->create($catalog, [
                    'code' => $row['code'], 'name' => $row['name'], 'active' => true, 'sort_order' => 0,
                    'description' => 'Emplazamiento transcrito del Plan de Ornato Restrepo 2025; SHA-256: '.self::SOURCE_SHA256.'.',
                ]);
                $placements->push($record);
                $created['placements']++;
            }

            return $created;
        }, 3);
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
