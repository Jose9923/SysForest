<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tree;
use App\Models\User;
use App\Repositories\SecurityEventRepository;
use App\Repositories\TreeCatalogRepository;
use App\Repositories\TreeRepository;
use App\Validation\TreeInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TreeLifecycleService
{
    public function __construct(
        private TreeWriteService $writes,
        private TreeStatusService $statuses,
        private TreeLocationService $locations,
        private TreeRepository $trees,
        private TreeCatalogRepository $catalogs,
        private SecurityEventRepository $events
    ) {
    }

    public function status(User $actor, string $id, array $input): Tree
    {
        return $this->writes->run($actor, $id, 'update', $input['lock_version'] ?? null, function (Tree $tree, User $actor) use ($input): void {
            $data = Validator::make($input, TreeInput::statusRules(), TreeInput::messages())->validate();
            $this->statuses->apply($tree, $actor, $data['current_status'], $data['reason'] ?? null);
        });
    }

    public function location(User $actor, string $id, array $input): Tree
    {
        return $this->writes->run($actor, $id, 'update', $input['lock_version'] ?? null, function (Tree $tree, User $actor) use ($input): void {
            $data = Validator::make($input, array_merge(TreeInput::identityGuards(), TreeInput::locationRules()), TreeInput::messages())->validate();
            $this->locations->apply($tree, $actor, $data);
        });
    }

    public function void(User $actor, string $id, array $input): Tree
    {
        return $this->writes->run($actor, $id, 'void', $input['lock_version'] ?? null, function (Tree $tree, User $actor) use ($input): void {
            $data = Validator::make($input, TreeInput::voidRules(), TreeInput::messages())->validate();
            if (!$this->catalogs->validSelection('deletion_reason_id', $data['deletion_reason_id'])) {
                throw ValidationException::withMessages(['deletion_reason_id' => 'Seleccione un motivo de anulación disponible.']);
            }
            $this->trees->void($tree, ['deleted_by' => $actor->id, 'deletion_reason_id' => $data['deletion_reason_id'],
                'deletion_notes' => $data['deletion_notes'] ?? null]);
            $this->events->record('tree.voided', $actor, $tree->organization_id, ['resource_id' => $tree->id,
                'resource_code' => $tree->code, 'reason' => $data['deletion_reason_id']]);
        });
    }

    public function restore(User $actor, string $id, array $input): Tree
    {
        return $this->writes->run($actor, $id, 'restore', $input['lock_version'] ?? null, function (Tree $tree, User $actor): void {
            $this->trees->restore($tree, (int) $actor->id);
            $this->events->record('tree.restored', $actor, $tree->organization_id, ['resource_id' => $tree->id, 'resource_code' => $tree->code]);
        }, true);
    }
}
