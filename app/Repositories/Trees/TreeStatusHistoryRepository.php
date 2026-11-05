<?php

declare(strict_types=1);

namespace App\Repositories\Trees;

use App\Models\Trees\Tree;
use App\Models\Trees\TreeStatusHistory;
use App\Models\User;

class TreeStatusHistoryRepository
{
    public function record(Tree $tree, User $actor, ?string $from, string $to, ?string $reason): TreeStatusHistory
    {
        $history = new TreeStatusHistory();
        $history->forceFill(['organization_id' => $tree->organization_id, 'tree_id' => $tree->id,
            'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now()])->save();

        return $history;
    }
}
