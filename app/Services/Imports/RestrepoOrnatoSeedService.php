<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Catalogs\CatalogRegistry;
use App\Enums\Inventories\InventoryStatus;
use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Models\Inventories\Inventory;
use App\Models\Organizations\Organization;
use App\Models\User;
use App\Repositories\Imports\RestrepoOrnatoSeedRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use App\Services\Catalogs\CatalogAccessService;
use App\Services\Catalogs\CatalogService;
use App\Services\Inventories\InventoryService;
use App\Services\Organizations\OrganizationService;
use App\Services\Auth\PermissionService;
use App\Services\Taxonomy\TaxonomyService;
use App\Services\Tenancy\TenantContextService;

/**
 * Prepara la carga de Ornato sin convertir etiquetas fuente en una revisión nomenclatural.
 * Los Services de dominio conservan validación, autorización, tenant y auditoría de cada alta.
 */
class RestrepoOrnatoSeedService
{
    private const SOURCE_SHA256 = '54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63';

    public function __construct(
        private RestrepoOrnatoSeedRepository $repository,
        private UserRepository $users,
        private PermissionService $permissions,
        private CatalogAccessService $catalogAccess,
        private CatalogRegistry $registry,
        private CatalogService $catalogs,
        private TaxonomyService $taxonomy,
        private OrganizationService $organizations,
        private InventoryService $inventories,
        private TenantContextService $tenants,
        private SecurityEventRepository $events,
    ) {
    }

    /**
     * La transacción revierte todas las altas si una referencia cambió desde la revisión del manifiesto.
     * Nunca actualiza, reactiva, restaura ni publica registros preexistentes.
     *
     * @return array<string, mixed> Identificadores del destino y cantidades creadas en esta ejecución.
     */
    public function seed(string $actorEmail): array
    {
        $manifest = json_decode(
            file_get_contents(database_path('seeders/data/restrepo-ornato-catalogs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (($manifest['source']['sha256'] ?? null) !== self::SOURCE_SHA256) {
            throw new LogicException('El manifiesto no corresponde a la planilla de Ornato revisada.');
        }
        $actor = $this->users->findByEmail($actorEmail)
            ?? throw new LogicException('No existe el actor autorizado para preparar Restrepo.');

        return DB::transaction(function () use ($actor, $manifest): array {
            // Serializa ejecuciones del mismo actor y revalida su autorización antes de cualquier alta.
            $actor = $this->users->findForUpdate((int) $actor->id);
            $this->catalogAccess->authorizeManage($actor);
            if (!$this->permissions->allowsNational($actor, 'territories.manage')
                || !$this->permissions->allowsNational($actor, 'organizations.create')) {
                throw new AuthorizationException('La preparación de Restrepo requiere autorización nacional vigente.');
            }
            $created = array_fill_keys(['municipalities', 'organizations', 'inventories', 'families', 'species', 'common_names', 'placements'], 0);
            $organization = $this->seedOrganization($actor, $manifest, $created);
            $this->seedTaxonomy($actor, $manifest, $created);
            $this->seedPlacements($actor, $manifest['placements'], $created);
            $this->validateReferences($manifest['references']);
            $inventory = $this->tenants->run(
                $actor,
                $organization->id,
                function () use ($actor, $organization, $manifest, &$created): Inventory {
                    return $this->seedInventory($actor, $organization, $manifest['inventory'], $created);
                },
            );

            return [
                'organization_id' => $organization->id, 'organization_code' => $organization->code,
                'inventory_id' => $inventory->id, 'inventory_code' => $inventory->code,
                'source_sha256' => self::SOURCE_SHA256, 'created' => $created,
            ];
        }, 3);
    }

    /** DANE 50606 identifica Restrepo, Meta; no se inventan polígonos ni límites geográficos. */
    private function seedOrganization(User $actor, array $manifest, array &$created): Organization
    {
        $territory = $manifest['territory'];
        $department = $this->repository->department($territory['country_code'], $territory['department_code']);
        if (!$department->active || !$department->country->active) {
            throw new LogicException('Colombia y Meta deben estar activos antes de preparar Restrepo.');
        }
        $municipality = $this->repository->municipality($territory['municipality_code']);
        if ($municipality === null) {
            $municipality = $this->repository->createMunicipality([
                'department_id' => $department->id, 'code' => $territory['municipality_code'],
                'name' => $territory['municipality_name'], 'active' => true,
            ]);
            $this->events->record('territory.municipality.created', $actor, null, [
                'resource_id' => $municipality->id, 'resource_code' => $municipality->code,
                'reason' => 'Alta de Restrepo, Meta, según DANE: '.$territory['source_url'],
            ]);
            $created['municipalities']++;
        } elseif (!$municipality->active || $municipality->department_id !== $department->id
            || $this->normalized($municipality->name) !== $this->normalized($territory['municipality_name'])) {
            throw new LogicException('El municipio 50606 existente requiere revisión; no se sobrescribe.');
        }

        $data = $manifest['organization'];
        $organization = $this->repository->organization($data['code']);
        if ($organization === null) {
            $organization = $this->organizations->create($actor, $data + ['municipality_id' => $municipality->id, 'active' => true]);
            $created['organizations']++;
        } elseif ($organization->trashed() || !$organization->active || $organization->is_public
            || $organization->municipality_id !== $municipality->id || $organization->type->value !== $data['type']
            || $organization->tree_code_prefix !== $data['tree_code_prefix']) {
            throw new LogicException('La institución RESTREPO-META existente requiere revisión; no se modifica.');
        }

        return $organization;
    }

    private function seedInventory(User $actor, Organization $organization, array $data, array &$created): Inventory
    {
        $inventory = $this->repository->inventory($organization->id, $data['code']);
        if ($inventory === null) {
            $inventory = $this->inventories->create($actor, $data + ['status' => 'active', 'active' => true]);
            $created['inventories']++;
        } elseif (!$inventory->active || $inventory->is_public || $inventory->status !== InventoryStatus::Active
            || $inventory->starts_at?->toDateString() !== $data['starts_at']
            || $inventory->ends_at?->toDateString() !== $data['ends_at']) {
            throw new LogicException('La campaña ORNATO-2025 existente requiere revisión; no se modifica.');
        }

        return $inventory;
    }

    private function seedTaxonomy(User $actor, array $manifest, array &$created): void
    {
        $familyRecords = $this->repository->taxonomy(TaxonomyCatalog::Families);
        $speciesRecords = $this->repository->taxonomy(TaxonomyCatalog::Species);
        $commonRecords = $this->repository->taxonomy(TaxonomyCatalog::CommonNames);
        $families = [];
        $description = 'Fuente: Planilla Plan Ornato Restrepo 2025 Total para riesgos.xlsx. SHA-256: '.self::SOURCE_SHA256
            .'. Etiqueta transcrita; no implica validación nomenclatural ni resolución de sinónimos.';

        foreach ($manifest['families'] as $row) {
            $family = $this->existing($familyRecords, $row, 'scientific_name');
            if ($family === null) {
                $family = $this->taxonomy->create($actor, 'families', [
                    'code' => $row['code'], 'scientific_name' => $row['scientific_name'],
                    'description' => $description, 'active' => true, 'sort_order' => 0,
                ]);
                $familyRecords->push($family);
                $created['families']++;
            }
            $families[$row['code']] = $family;
        }
        foreach ($manifest['species'] as $row) {
            $family = $families[$row['family_code']];
            $species = $this->existing($speciesRecords, $row, 'scientific_name', ['family_id' => $family->id]);
            if ($species === null) {
                $species = $this->taxonomy->create($actor, 'species', [
                    'code' => $row['code'], 'scientific_name' => $row['scientific_name'], 'family_id' => $family->id,
                    'description' => $description, 'active' => true, 'sort_order' => 0,
                ]);
                $speciesRecords->push($species);
                $created['species']++;
            }
            foreach ($row['common_names'] as $commonRow) {
                $common = $this->existing($commonRecords, $commonRow, 'name', ['species_id' => $species->id]);
                if ($common !== null) {
                    continue;
                }
                $primaryExists = $commonRecords->contains(fn (Model $record): bool => $record->species_id === $species->id && $record->is_primary);
                $common = $this->taxonomy->create($actor, 'common-names', [
                    'code' => $commonRow['code'], 'name' => $commonRow['name'], 'species_id' => $species->id,
                    'is_primary' => !$primaryExists, 'description' => $description, 'active' => true, 'sort_order' => 0,
                ]);
                $commonRecords->push($common);
                $created['common_names']++;
            }
        }
    }

    private function seedPlacements(User $actor, array $rows, array &$created): void
    {
        $records = $this->repository->catalog($this->registry->find('placements'));
        foreach ($rows as $row) {
            if ($this->existing($records, $row, 'name', accentInsensitive: true) !== null) {
                continue;
            }
            $entry = $this->catalogs->create($actor, 'placements', [
                'code' => $row['code'], 'name' => $row['name'], 'active' => true, 'sort_order' => 0,
                'description' => 'Emplazamiento transcrito del Plan de Ornato Restrepo 2025; SHA-256: '.self::SOURCE_SHA256.'.',
            ]);
            $records->push($entry);
            $created['placements']++;
        }
    }

    private function validateReferences(array $references): void
    {
        foreach (['growth_habits' => 'growth-habits', 'establishment_origins' => 'establishment-origins', 'biogeographic_origins' => 'biogeographic-origins'] as $key => $catalog) {
            $records = $this->repository->catalog($this->registry->find($catalog));
            foreach ($references[$key] as $row) {
                $this->existing($records, $row + ['expected_existing' => true], 'name');
            }
        }
    }

    /**
     * Una coincidencia se reutiliza solo si código, significado, padre y vigencia siguen concordando.
     * La normalización taxonómica ignora únicamente mayúsculas y espacios; no interpreta sinonimia.
     */
    private function existing(Collection $records, array $row, string $nameField, array $parent = [], bool $accentInsensitive = false): ?Model
    {
        $record = $records->firstWhere('code', $row['code']);
        $matches = function (Model $candidate) use ($row, $nameField, $parent, $accentInsensitive): bool {
            if ($this->normalized($candidate->getAttribute($nameField), $accentInsensitive)
                !== $this->normalized($row[$nameField], $accentInsensitive)) {
                return false;
            }
            foreach ($parent as $field => $value) {
                if ($candidate->getAttribute($field) !== $value) {
                    return false;
                }
            }

            return true;
        };
        if ($record !== null) {
            if (!$record->active || !$matches($record)) {
                throw new LogicException('La referencia '.$row['code'].' cambió o está inactiva; requiere revisión.');
            }

            return $record;
        }
        if (($row['expected_existing'] ?? false) || $records->contains($matches)) {
            throw new LogicException('La referencia '.$row['code'].' no coincide con el catálogo revisado; no se duplica.');
        }

        return null;
    }

    private function normalized(string $value, bool $accentInsensitive = false): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));

        return $accentInsensitive ? Str::ascii($normalized) : $normalized;
    }
}
