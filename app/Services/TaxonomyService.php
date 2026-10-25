<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaxonomyCatalog;
use App\Models\User;
use App\Repositories\SecurityEventRepository;
use App\Repositories\TaxonomyRepository;
use App\Repositories\UserRepository;
use App\Validation\TaxonomyRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TaxonomyService
{
    public function __construct(
        private TaxonomyRepository $repository,
        private CatalogAccessService $access,
        private UserRepository $users,
        private SecurityEventRepository $events,
        private VersionedWriteService $versions,
        private AuditSnapshotService $snapshots,
    ) {
    }

    public function index(User $actor, string $key, array $input = []): array
    {
        $this->access->authorizeView($actor);
        $catalog = TaxonomyCatalog::fromKey($key);
        $canManage = $this->access->canManage($actor);
        $filters = Validator::make($input, ['q' => ['nullable', 'string', 'max:150'], 'active' => ['nullable', 'in:all,0,1']])->validate();
        $filters = ['q' => trim($filters['q'] ?? ''), 'active' => $canManage ? (string) ($filters['active'] ?? 'all') : '1'];

        return ['catalog' => $key, 'title' => $catalog->label(), 'nameColumn' => $catalog->nameColumn(),
            'canManage' => $canManage, 'filters' => $filters, 'records' => $this->repository->paginate($catalog, $filters)];
    }

    public function createForm(User $actor, string $key): array
    {
        $this->access->authorizeManage($actor);

        return $this->form(TaxonomyCatalog::fromKey($key));
    }

    public function editForm(User $actor, string $key, string $id): array
    {
        $this->access->authorizeManage($actor);
        $catalog = TaxonomyCatalog::fromKey($key);

        return $this->form($catalog, $this->repository->find($catalog, $id));
    }

    public function create(User $actor, string $key, array $input): Model
    {
        return $this->persist($actor, TaxonomyCatalog::fromKey($key), null, $input);
    }

    public function update(User $actor, string $key, string $id, array $input): Model
    {
        return $this->persist($actor, TaxonomyCatalog::fromKey($key), $id, $input);
    }

    private function persist(User $actor, TaxonomyCatalog $catalog, ?string $id, array $input): Model
    {
        try {
            return DB::transaction(function () use ($actor, $catalog, $id, $input): Model {
                $currentActor = $this->users->findForUpdate((int) $actor->id);
                $this->access->authorizeManage($currentActor);
                $record = $id === null ? null : $this->repository->find($catalog, $id, true);
                $base = $record?->only(array_diff(array_keys(TaxonomyRules::for($catalog)), ['id', 'organization_id', 'lock_version'])) ?? ['active' => true, 'sort_order' => 0];
                $values = Validator::make(array_replace($base, $input), TaxonomyRules::for($catalog, $record !== null))->validate();
                if ($record !== null && $values['code'] !== $record->code) {
                    throw ValidationException::withMessages(['code' => 'El código es estable y no puede cambiarse.']);
                }
                $values[$catalog->nameColumn()] = trim($values[$catalog->nameColumn()]);
                $this->validateParent($catalog, $values, $record);
                if ($record !== null && array_key_exists('description', $values)) {
                    $values['description'] = \App\Support\ExcelReferencePresentation::preserveDescription($record->description, $values['description']);
                }
                $auditBefore = $record === null ? [] : $this->snapshots->snapshot($record);
                if ($record !== null) {
                    $this->versions->advance($record, $values);
                }
                unset($values['lock_version']);
                $before = $record?->only(['active', 'sort_order']);
                $record = $record === null ? $this->repository->create($catalog, $values) : $this->repository->update($record, $values);
                $metadata = ['catalog_key' => 'taxonomy.'.$catalog->value, 'resource_id' => $record->id, 'resource_code' => $record->code];
                $this->events->recordChange(
                    $before === null ? 'catalog.created' : 'catalog.updated',
                    $currentActor,
                    null,
                    'catalog',
                    $record->id,
                    $auditBefore,
                    $this->snapshots->snapshot($record),
                    $metadata
                );
                if ($before !== null && $before['active'] !== $record->active) {
                    $this->events->recordChange(
                        $record->active ? 'catalog.activated' : 'catalog.deactivated',
                        $currentActor,
                        null,
                        'catalog',
                        $record->id,
                        ['active' => $before['active']],
                        ['active' => $record->active],
                        $metadata
                    );
                }
                if ($before !== null && $before['sort_order'] !== $record->sort_order) {
                    $this->events->recordChange(
                        'catalog.reordered',
                        $currentActor,
                        null,
                        'catalog',
                        $record->id,
                        ['sort_order' => $before['sort_order']],
                        ['sort_order' => $record->sort_order],
                        $metadata
                    );
                }

                return $record;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Ya existe ese código, nombre científico o nombre común para la especie.']);
        }
    }

    private function validateParent(TaxonomyCatalog $catalog, array $values, ?Model $record): void
    {
        if ($catalog === TaxonomyCatalog::Families) {
            return;
        }
        $field = $catalog === TaxonomyCatalog::Species ? 'family_id' : 'species_id';
        $parentCatalog = $catalog === TaxonomyCatalog::Species ? TaxonomyCatalog::Families : TaxonomyCatalog::Species;
        try {
            $parent = $this->repository->find($parentCatalog, $values[$field], true);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([$field => 'Selecciona una referencia taxonómica existente.']);
        }
        $newReference = $record === null || $record->getAttribute($field) !== $values[$field]
            || (! $record->active && (bool) $values['active']);
        $family = $catalog === TaxonomyCatalog::CommonNames
            ? $this->repository->find(TaxonomyCatalog::Families, $parent->family_id, true) : null;
        if ($newReference && (! $parent->active || ($family !== null && ! $family->active))) {
            throw ValidationException::withMessages([$field => 'La referencia taxonómica debe estar activa.']);
        }
        if ($catalog === TaxonomyCatalog::CommonNames && ($values['is_primary'] ?? false)
            && $this->repository->conflictingPrimary($parent->id, $record?->id)) {
            throw ValidationException::withMessages(['is_primary' => 'La especie ya tiene un nombre principal. Desmarca el anterior antes de elegir otro.']);
        }
    }

    private function form(TaxonomyCatalog $catalog, ?Model $record = null): array
    {
        return ['catalog' => $catalog->value, 'title' => $catalog->label(), 'nameColumn' => $catalog->nameColumn(), 'record' => $record,
            'families' => $catalog === TaxonomyCatalog::Species ? $this->repository->options(TaxonomyCatalog::Families, $record?->family_id) : collect(),
            'species' => $catalog === TaxonomyCatalog::CommonNames ? $this->repository->options(TaxonomyCatalog::Species, $record?->species_id) : collect()];
    }
}
