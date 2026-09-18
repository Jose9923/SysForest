<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\TreeInspectionRepository;
use App\Repositories\TreeRepository;
use App\Validation\EvidenceInput;
use Illuminate\Support\Facades\Gate;

/** Resuelve primero el tenant y después el propietario; la inspección conserva su árbol raíz. */
class EvidenceOwnerService
{
    public function __construct(private ForestryAccessService $access, private TreeRepository $trees, private TreeInspectionRepository $inspections)
    {
    }

    public function resolve(User $actor, ?string $treeId, ?string $inspectionId, bool $lock = false): Tree|TreeInspection
    {
        EvidenceInput::owner($treeId, $inspectionId);
        $organization = $this->access->organization($actor, $lock);
        if ($treeId !== null) {
            $owner = $this->trees->findForTenant($organization->id, $treeId, $lock, true);
        } else {
            $reference = $this->inspections->findForTenant($organization->id, $inspectionId);
            $tree = $this->trees->findForTenant($organization->id, $reference->tree_id, $lock, true);
            $owner = $this->inspections->findForTenant($organization->id, $inspectionId, $lock);
            $owner->setRelation('tree', $tree);
        }
        Gate::forUser($actor)->authorize('view', $owner);

        return $owner;
    }

    public function url(Tree|TreeInspection $owner): string
    {
        return route($owner instanceof Tree ? 'trees.show' : 'inspections.show', $owner->id);
    }
}
