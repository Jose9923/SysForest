<?php

declare(strict_types=1);

namespace App\Policies\Maintenances;

use App\Enums\Maintenances\MaintenanceStatus;
use App\Models\Maintenances\Maintenance;
use App\Models\Trees\Tree;
use App\Models\User;
use App\Services\Forestry\ForestryAccessService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

class MaintenancePolicy
{
    public function __construct(private ForestryAccessService $access, private TenantContext $context)
    {
    }

    public function viewAny(User $actor): bool
    {
        return $this->access->allows($actor, 'maintenances.view');
    }

    public function create(User $actor, Tree $tree): bool
    {
        return $this->sameContext($actor, $tree->organization_id) && !$tree->trashed()
            && $this->access->allows($actor, 'maintenances.create') && Gate::forUser($actor)->allows('view', $tree);
    }

    public function view(User $actor, Maintenance $maintenance): bool
    {
        return $this->sameContext($actor, $maintenance->organization_id)
            && $this->access->allows($actor, 'maintenances.view')
            && (!$maintenance->trashed() || $this->access->allows($actor, 'maintenances.restore'))
            && Gate::forUser($actor)->allows('view', $maintenance->tree);
    }

    public function update(User $actor, Maintenance $maintenance): bool
    {
        return $this->operable($actor, $maintenance) && $maintenance->status->allowsMetadataChanges()
            && $this->access->allows($actor, 'maintenances.update');
    }

    public function transition(User $actor, Maintenance $maintenance): bool
    {
        return $this->operable($actor, $maintenance) && $maintenance->status->allowsMetadataChanges()
            && $this->access->allows($actor, 'maintenances.transition');
    }

    public function verify(User $actor, Maintenance $maintenance): bool
    {
        return $this->operable($actor, $maintenance) && $maintenance->status === MaintenanceStatus::Executed
            && $this->access->allows($actor, 'maintenances.verify');
    }

    public function void(User $actor, Maintenance $maintenance): bool
    {
        return $this->view($actor, $maintenance) && !$maintenance->trashed()
            && $this->access->allows($actor, 'maintenances.void');
    }

    public function restore(User $actor, Maintenance $maintenance): bool
    {
        return $this->view($actor, $maintenance) && $maintenance->trashed() && !$maintenance->tree->trashed()
            && $this->access->allows($actor, 'maintenances.restore');
    }

    public function forceDelete(User $actor, Maintenance $maintenance): bool
    {
        return false;
    }

    private function operable(User $actor, Maintenance $maintenance): bool
    {
        return $this->view($actor, $maintenance) && !$maintenance->trashed() && !$maintenance->tree->trashed();
    }

    private function sameContext(User $actor, string $organizationId): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $organizationId;
    }
}
