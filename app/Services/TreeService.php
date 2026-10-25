<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tree;
use App\Models\User;
use App\Repositories\TreeRepository;
use App\Repositories\UserRepository;
use App\Repositories\SecurityEventRepository;
use App\Validation\TreeInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class TreeService
{
    private const ATTRIBUTES = ['external_number', 'location_description', 'placement_id', 'species_id',
        'growth_habit_id', 'establishment_origin_id', 'biogeographic_origin_id', 'is_protected', 'is_heritage'];

    public function __construct(
        private ForestryAccessService $access,
        private UserRepository $users,
        private TreeRepository $trees,
        private TreeCodeService $codes,
        private TreeRelationService $relations,
        private TreeStatusService $statuses,
        private TreeLocationService $locations,
        private TreeWriteService $writes,
        private SecurityEventRepository $events,
        private CarbonEstimationService $carbon,
        private AuditSnapshotService $snapshots
    ) {
    }

    /** Identidad, consecutivo, campañas y ambos historiales se confirman o revierten juntos. */
    public function create(User $actor, array $input): Tree
    {
        return $this->createWithCode($actor, $input);
    }

    /** La plantilla puede conservar un código oficial validado sin ampliar el contrato HTTP habitual. */
    public function createImported(User $actor, array $input, ?string $code = null): Tree
    {
        return $this->createWithCode($actor, $input, $code);
    }

    private function createWithCode(User $actor, array $input, ?string $code = null): Tree
    {
        return DB::transaction(function () use ($actor, $input, $code): Tree {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            Gate::forUser($actor)->authorize('create', Tree::class);
            $data = Validator::make($input, TreeInput::rules(), TreeInput::messages())->validate();
            $this->relations->validateCatalogs($data);
            $tree = $this->trees->create(array_merge($this->attributes($data), [
                'organization_id' => $organization->id, 'code' => $code === null ? $this->codes->next($organization) : $this->codes->imported($organization, $code),
                'created_by' => $actor->id, 'updated_by' => $actor->id, 'current_status' => $data['current_status'], 'lock_version' => 1,
            ]));
            $this->relations->include($tree, $actor, $data['inventory_ids']);
            $this->statuses->apply($tree, $actor, $data['current_status'], $data['reason'] ?? null, true);
            $this->locations->apply($tree, $actor, $data, true);
            $tree = $this->trees->findForTenant($organization->id, $tree->id);
            $this->events->recordChange(
                'tree.created',
                $actor,
                $organization->id,
                'tree',
                $tree->id,
                [],
                $this->snapshots->snapshot($tree),
                ['resource_id' => $tree->id, 'resource_code' => $tree->code]
            );

            return $tree;
        }, 3);
    }

    public function update(User $actor, string $id, array $input): Tree
    {
        return $this->writes->run($actor, $id, 'update', $input['lock_version'] ?? null, function (Tree $tree, User $actor) use ($input): void {
            $data = Validator::make($input, TreeInput::rules(true), TreeInput::messages())->validate();
            $this->relations->validateCatalogs($data, $tree);
            $this->trees->update($tree, $this->attributes($data));
            $this->relations->include($tree, $actor, $data['inventory_ids'] ?? []);
            if (isset($data['current_status'])) {
                $this->statuses->apply($tree, $actor, $data['current_status'], $data['reason'] ?? null);
            }
            $this->locations->apply($tree, $actor, $data);
            if (array_key_exists('species_id', $data) || array_key_exists('growth_habit_id', $data)) {
                $this->carbon->forTree($tree->organization_id, $tree->id);
            }
        });
    }

    public function include(User $actor, string $id, array $input): Tree
    {
        return $this->writes->run($actor, $id, 'update', $input['lock_version'] ?? null, function (Tree $tree, User $actor) use ($input): void {
            $data = Validator::make($input, ['inventory_ids' => ['required', 'array', 'min:1', 'max:50'],
                'inventory_ids.*' => ['required', 'uuid', 'distinct'], 'notes' => ['nullable', 'string', 'max:2000']], TreeInput::messages())->validate();
            $this->relations->include($tree, $actor, $data['inventory_ids'], $data['notes'] ?? null);
        }, false, 'tree.inventory_included');
    }

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(self::ATTRIBUTES));
    }
}
