<?php

declare(strict_types=1);

namespace App\Services;

use App\Catalogs\CatalogInput;
use App\Catalogs\CatalogRegistry;
use App\Models\CatalogEntry;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function __construct(
        private CatalogRegistry $catalogs,
        private CatalogRepository $entries,
        private CatalogAccessService $access,
        private UserRepository $users,
        private SecurityEventRepository $events,
        private VersionedWriteService $versions,
        private AuditSnapshotService $snapshots,
    ) {
    }

    public function dashboard(User $actor): array
    {
        $this->access->authorizeView($actor);
        $cards = [];
        foreach ($this->catalogs->all() as $definition) {
            $cards[] = ['catalog' => $definition, 'counts' => $this->entries->counts($definition)];
        }

        return ['cards' => $cards, 'canManage' => $this->access->canManage($actor)];
    }

    public function index(User $actor, string $key, array $filters): array
    {
        $this->access->authorizeView($actor);
        $catalog = $this->catalogs->find($key);

        return ['catalog' => $catalog, 'entries' => $this->entries->paginate($catalog, $filters),
            'filters' => $filters, 'canManage' => $this->access->canManage($actor)];
    }

    public function form(User $actor, string $key, ?string $id = null): array
    {
        $this->access->authorizeManage($actor);
        $catalog = $this->catalogs->find($key);

        return ['catalog' => $catalog, 'entry' => $id ? $this->entries->find($catalog, $id) : null];
    }

    public function options(User $actor, string $key): Collection
    {
        $this->access->authorizeView($actor);

        return $this->entries->activeOptions($this->catalogs->find($key));
    }

    public function historical(User $actor, string $key, string $id): CatalogEntry
    {
        $this->access->authorizeView($actor);

        return $this->entries->find($this->catalogs->find($key), $id);
    }

    public function create(User $actor, string $key, array $input): CatalogEntry
    {
        return $this->save($actor, $key, null, $input);
    }

    public function update(User $actor, string $key, string $id, array $input): CatalogEntry
    {
        return $this->save($actor, $key, $id, $input);
    }

    /** Actor, catálogo y auditoría comparten transacción; el código identifica un significado estable. */
    private function save(User $actor, string $key, ?string $id, array $input): CatalogEntry
    {
        $this->access->authorizeManage($actor);
        $catalog = $this->catalogs->find($key);

        try {
            return DB::transaction(function () use ($actor, $catalog, $id, $input): CatalogEntry {
                $actor = $this->users->findForUpdate((int) $actor->id);
                $this->access->authorizeManage($actor);
                $entry = $id ? $this->entries->find($catalog, $id, true) : null;
                $data = Validator::make($input, CatalogInput::rules($entry !== null))->validate();
                $data['name'] = trim($data['name']);
                unset($data['organization_id']);
                if ($entry && $entry->code !== $data['code']) {
                    throw ValidationException::withMessages(['code' => 'El código es inmutable. Un significado nuevo requiere otro registro.']);
                }
                if ($this->entries->codeExists($catalog, $data['code'], $id)) {
                    throw ValidationException::withMessages(['code' => 'El código ya existe en este catálogo.']);
                }
                if ($entry !== null && array_key_exists('description', $data)) {
                    $data['description'] = \App\Support\ExcelReferencePresentation::preserveDescription($entry->description, $data['description']);
                }
                $before = $entry === null ? [] : $this->snapshots->snapshot($entry);
                if ($entry !== null) {
                    $this->versions->advance($entry, $data);
                }
                unset($data['lock_version']);
                $previousActive = $entry?->active;
                $previousOrder = $entry?->sort_order;
                $entry = $entry ? $this->entries->update($entry, $data) : $this->entries->create($catalog, $data);
                $metadata = ['catalog_key' => $catalog->key, 'resource_id' => $entry->id, 'resource_code' => $entry->code];
                $this->events->recordChange(
                    $id ? 'catalog.updated' : 'catalog.created',
                    $actor,
                    null,
                    'catalog',
                    $entry->id,
                    $before,
                    $this->snapshots->snapshot($entry),
                    $metadata
                );
                if ($id && $previousActive !== $entry->active) {
                    $this->events->recordChange(
                        $entry->active ? 'catalog.activated' : 'catalog.deactivated',
                        $actor,
                        null,
                        'catalog',
                        $entry->id,
                        ['active' => $previousActive],
                        ['active' => $entry->active],
                        $metadata
                    );
                }
                if ($id && $previousOrder !== $entry->sort_order) {
                    $this->events->recordChange(
                        'catalog.reordered',
                        $actor,
                        null,
                        'catalog',
                        $entry->id,
                        ['sort_order' => $previousOrder],
                        ['sort_order' => $entry->sort_order],
                        $metadata
                    );
                }

                return $entry;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['code' => 'El código ya existe en este catálogo.']);
            }
            throw $exception;
        }
    }
}
