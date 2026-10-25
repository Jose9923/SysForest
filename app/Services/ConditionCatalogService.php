<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConditionCatalogKind;
use App\Models\User;
use App\Repositories\ConditionCatalogRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use App\Validation\ConditionCatalogRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConditionCatalogService
{
    public function __construct(
        private ConditionCatalogRepository $repository,
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
        $catalog = ConditionCatalogKind::fromKey($key);
        $canManage = $this->access->canManage($actor);
        $filters = Validator::make($input, ['q' => ['nullable', 'string', 'max:150'], 'active' => ['nullable', 'in:all,0,1'],
            'assessment_type' => ['nullable', 'in:physical_mechanical,phytosanitary']])->validate();
        $filters = ['q' => trim($filters['q'] ?? ''), 'active' => $canManage ? (string) ($filters['active'] ?? 'all') : '1',
            'assessment_type' => $filters['assessment_type'] ?? ''];

        return ['catalog' => $key, 'title' => $catalog->label(), 'canManage' => $canManage, 'filters' => $filters,
            'records' => $this->repository->paginate($catalog, $filters)];
    }

    public function createForm(User $actor, string $key): array
    {
        $this->access->authorizeManage($actor);

        return $this->form(ConditionCatalogKind::fromKey($key));
    }

    public function editForm(User $actor, string $key, string $id): array
    {
        $this->access->authorizeManage($actor);
        $catalog = ConditionCatalogKind::fromKey($key);

        return $this->form($catalog, $this->repository->find($catalog, $id));
    }

    public function create(User $actor, string $key, array $input): Model
    {
        return $this->persist($actor, ConditionCatalogKind::fromKey($key), null, $input);
    }

    public function update(User $actor, string $key, string $id, array $input): Model
    {
        return $this->persist($actor, ConditionCatalogKind::fromKey($key), $id, $input);
    }

    private function persist(User $actor, ConditionCatalogKind $catalog, ?string $id, array $input): Model
    {
        try {
            return DB::transaction(function () use ($actor, $catalog, $id, $input): Model {
                $currentActor = $this->users->findForUpdate((int) $actor->id);
                $this->access->authorizeManage($currentActor);
                $record = $id === null ? null : $this->repository->find($catalog, $id, true);
                $base = $record?->only(array_diff(array_keys(ConditionCatalogRules::for($catalog)), ['id', 'organization_id', 'lock_version'])) ?? ['active' => true, 'sort_order' => 0];
                $values = Validator::make(array_replace($base, $input), ConditionCatalogRules::for($catalog, $record !== null))->validate();
                foreach (['code', 'assessment_type', 'condition_category_id'] as $immutable) {
                    if ($record !== null && array_key_exists($immutable, $values) && $values[$immutable] !== $record->getAttribute($immutable)) {
                        throw ValidationException::withMessages([$immutable => 'El código, ámbito y categoría originales deben conservarse. Crea una entrada distinta si cambia el significado.']);
                    }
                }
                $values['name'] = trim($values['name']);
                if ($catalog === ConditionCatalogKind::Items) {
                    $this->validateCategory($values, $record);
                }
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
                $metadata = ['catalog_key' => 'conditions.'.$catalog->value, 'resource_id' => $record->id, 'resource_code' => $record->code];
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
            throw ValidationException::withMessages(['code' => 'Ya existe ese código o nombre dentro de la misma categoría.']);
        }
    }

    private function validateCategory(array $values, ?Model $record): void
    {
        try {
            $category = $this->repository->find(ConditionCatalogKind::Categories, $values['condition_category_id'], true);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['condition_category_id' => 'Selecciona una categoría existente.']);
        }
        if ($category->assessment_type !== $values['assessment_type']) {
            throw ValidationException::withMessages(['assessment_type' => 'El ámbito debe coincidir con la categoría seleccionada.']);
        }
        if (($record === null || (! $record->active && (bool) $values['active'])) && ! $category->active) {
            throw ValidationException::withMessages(['condition_category_id' => 'La categoría debe estar activa.']);
        }
    }

    private function form(ConditionCatalogKind $catalog, ?Model $record = null): array
    {
        return ['catalog' => $catalog->value, 'title' => $catalog->label(), 'record' => $record,
            'categories' => $catalog === ConditionCatalogKind::Items ? $this->repository->categoryOptions($record?->condition_category_id) : collect()];
    }
}
