<?php

declare(strict_types=1);

namespace App\Services\Catalogs;

use App\Catalogs\ClassificationMetadataInput;
use App\Models\Catalogs\ClassificationRuleVersion;
use App\Models\User;
use App\Repositories\Catalogs\ClassificationRuleRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\Audit\AuditSnapshotService;
use App\Services\Forestry\ClassificationService;
use App\Services\Shared\VersionedWriteService;

/** Presentación autorizada y administración de metadatos; las fórmulas permanecen versionadas. */
class ClassificationCatalogService
{
    public function __construct(
        private ClassificationRuleRepository $rules,
        private ClassificationService $calculations,
        private CatalogAccessService $access,
        private UserRepository $users,
        private SecurityEventRepository $events,
        private VersionedWriteService $versions,
        private AuditSnapshotService $snapshots,
    ) {
    }

    public function index(User $actor, array $filters): array
    {
        $this->access->authorizeView($actor);

        return ['versions' => $this->rules->paginate($filters['q'] ?? null, isset($filters['active']) ? (string) $filters['active'] === '1' : null)->withQueryString(),
            'filters' => $filters, 'canManage' => $this->access->canManage($actor)];
    }

    public function show(User $actor, string $id, array $input = []): array
    {
        $this->access->authorizeView($actor);
        $version = $this->rules->findSealed($id);
        $result = null;
        if (isset($input['value'])) {
            $data = Validator::make($input, [
                'value' => ['required', 'string', 'max:32'], 'kind' => ['required', 'in:diameter,height'],
            ])->validate();
            $result = $data['kind'] === 'diameter'
                ? $this->calculations->diameter($data['value'], $id)
                : $this->calculations->height($data['value'], $id);
        }

        return ['version' => $version, 'result' => $result, 'input' => $input,
            'canManage' => $this->access->canManage($actor)];
    }

    public function edit(User $actor, string $id): array
    {
        $this->access->authorizeManage($actor);

        return ['version' => $this->rules->findSealed($id)];
    }

    public function update(User $actor, string $id, array $input): ClassificationRuleVersion
    {
        $this->access->authorizeManage($actor);

        return DB::transaction(function () use ($actor, $id, $input): ClassificationRuleVersion {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $this->access->authorizeManage($actor);
            $version = $this->rules->find($id, true);
            $data = Validator::make($input, ClassificationMetadataInput::rules())->validate();
            if (array_key_exists('description', $data)) {
                $data['description'] = \App\Support\Imports\ExcelReferencePresentation::preserveDescription($version->description, $data['description']);
            }
            $before = $this->snapshots->snapshot($version);
            $this->versions->advance($version, $data);
            $data = array_intersect_key($data, array_flip(['name', 'description', 'active', 'sort_order']));
            $previousActive = $version->active;
            $previousOrder = $version->sort_order;
            $version = $this->rules->updateMetadata($version, $data);
            $metadata = ['catalog_key' => 'classification-rules', 'resource_id' => $version->id, 'rule_version' => $version->code];
            $this->events->recordChange(
                'catalog.updated',
                $actor,
                null,
                'catalog',
                $version->id,
                $before,
                $this->snapshots->snapshot($version),
                $metadata
            );
            if ($previousActive !== $version->active) {
                $this->events->recordChange(
                    $version->active ? 'catalog.activated' : 'catalog.deactivated',
                    $actor,
                    null,
                    'catalog',
                    $version->id,
                    ['active' => $previousActive],
                    ['active' => $version->active],
                    $metadata
                );
            }
            if ($previousOrder !== $version->sort_order) {
                $this->events->recordChange(
                    'catalog.reordered',
                    $actor,
                    null,
                    'catalog',
                    $version->id,
                    ['sort_order' => $previousOrder],
                    ['sort_order' => $version->sort_order],
                    $metadata
                );
            }

            return $version;
        }, 3);
    }
}
