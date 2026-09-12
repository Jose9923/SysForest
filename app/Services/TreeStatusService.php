<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TreeStatus;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\TreeRepository;
use App\Repositories\TreeStatusHistoryRepository;
use App\Repositories\SecurityEventRepository;

/** El estado describe al individuo real; nunca modifica deleted_at ni representa anulación. */
class TreeStatusService
{
    public function __construct(private TreeRepository $trees, private TreeStatusHistoryRepository $history, private SecurityEventRepository $events)
    {
    }

    public function apply(Tree $tree, User $actor, string $status, ?string $reason = null, bool $initial = false): bool
    {
        $previous = $tree->current_status instanceof TreeStatus ? $tree->current_status->value : $tree->current_status;
        if (!$initial && $previous === $status) {
            return false;
        }
        $this->history->record($tree, $actor, $initial ? null : $previous, $status, $reason);
        $this->trees->update($tree, ['current_status' => $status]);
        $this->events->record('tree.status_changed', $actor, $tree->organization_id, ['resource_id' => $tree->id, 'resource_code' => $tree->code]);

        return true;
    }
}
