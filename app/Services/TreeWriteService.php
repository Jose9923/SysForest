<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRecordException;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\TreeRepository;
use App\Repositories\UserRepository;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Validation\TreeInput;

/** Orden de bloqueo compartido: actor, contexto institucional y recurso. Protege también llamadas desde jobs. */
class TreeWriteService
{
    public function __construct(private ForestryAccessService $access, private UserRepository $users, private TreeRepository $trees, private AuditSnapshotService $snapshots, private \App\Repositories\SecurityEventRepository $events)
    {
    }

    public function run(User $actor, string $id, string $ability, mixed $version, Closure $operation, bool $withTrashed = false, ?string $event = 'tree.updated'): Tree
    {
        return DB::transaction(function () use ($actor, $id, $ability, $version, $operation, $withTrashed, $event): Tree {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $tree = $this->trees->findForTenant($organization->id, $id, true, $withTrashed);
            Gate::forUser($actor)->authorize($ability, $tree);
            if (!is_int($version) && (!is_string($version) || preg_match('/\A[1-9][0-9]*\z/D', $version) !== 1)) {
                throw ValidationException::withMessages(['lock_version' => 'La versión debe ser un entero positivo.']);
            }
            $version = (int) Validator::make(['lock_version' => $version], [
                'lock_version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            ], TreeInput::messages())->validate()['lock_version'];
            if ((int) $tree->lock_version !== $version) {
                throw new StaleRecordException();
            }
            $before = $this->snapshots->snapshot($tree);
            $operation($tree, $actor);
            $this->trees->update($tree, ['updated_by' => $actor->id, 'lock_version' => $version + 1]);

            $updated = $this->trees->findForTenant($organization->id, $id, false, true);
            if ($event !== null) {
                $this->events->recordChange(
                    $event,
                    $actor,
                    $organization->id,
                    'tree',
                    $tree->id,
                    $before,
                    $this->snapshots->snapshot($updated),
                    ['resource_id' => $tree->id, 'resource_code' => $tree->code]
                );
            }

            return $updated;
        }, 3);
    }
}
