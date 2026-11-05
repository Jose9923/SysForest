<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use App\Models\Maintenances\Maintenance;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Repositories\Maintenances\MaintenanceRepository;
use App\Repositories\Inspections\TreeInspectionRepository;
use App\Repositories\Trees\TreeRepository;
use App\Validation\Evidences\EvidenceInput;
use Illuminate\Support\Facades\Gate;
use App\Services\Forestry\ForestryAccessService;

/** Resuelve primero el tenant y después el propietario; inspecciones y mantenimientos conservan su árbol raíz. */
class EvidenceOwnerService
{
    public function __construct(private ForestryAccessService $access, private TreeRepository $trees, private TreeInspectionRepository $inspections, private MaintenanceRepository $maintenances)
    {
    }

    public function resolve(User $actor, ?string $treeId, ?string $inspectionId, bool $lock = false, ?string $maintenanceId = null): Tree|TreeInspection|Maintenance
    {
        EvidenceInput::owner($treeId, $inspectionId, $maintenanceId);
        $organization = $this->access->organization($actor, $lock);
        if ($treeId !== null) {
            $owner = $this->trees->findForTenant($organization->id, $treeId, $lock, true);
        } elseif ($inspectionId !== null) {
            $reference = $this->inspections->findForTenant($organization->id, $inspectionId);
            $tree = $this->trees->findForTenant($organization->id, $reference->tree_id, $lock, true);
            $owner = $this->inspections->findForTenant($organization->id, $inspectionId, $lock);
            $owner->setRelation('tree', $tree);
        } else {
            $reference = $this->maintenances->findForTenant($organization->id, $maintenanceId, false, true);
            $tree = $this->trees->findForTenant($organization->id, $reference->tree_id, $lock, true);
            $owner = $this->maintenances->findForTenant($organization->id, $maintenanceId, $lock, true);
            $owner->setRelation('tree', $tree);
        }
        Gate::forUser($actor)->authorize('view', $owner);

        return $owner;
    }

    public function url(Tree|TreeInspection|Maintenance $owner): string
    {
        return route(match (true) {
            $owner instanceof Tree => 'trees.show',
            $owner instanceof TreeInspection => 'inspections.show',
            default => 'maintenances.show',
        }, $owner->id);
    }
}
