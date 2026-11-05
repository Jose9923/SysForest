<?php

declare(strict_types=1);

namespace App\Policies\Inspections;

use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Services\Forestry\ForestryAccessService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/** La colaboración institucional no depende del autor ni exige una campaña activa. */
class TreeInspectionPolicy
{
    public function __construct(private ForestryAccessService $access, private TenantContext $context)
    {
    }

    public function viewAny(User $actor): bool
    {
        return $this->access->allows($actor, 'inspections.view');
    }

    public function create(User $actor, Tree $tree): bool
    {
        return $this->sameContext($actor, $tree->organization_id) && !$tree->trashed()
            && $this->access->allows($actor, 'inspections.create');
    }

    public function view(User $actor, TreeInspection $inspection): bool
    {
        return $this->sameContext($actor, $inspection->organization_id)
            && $this->access->allows($actor, 'inspections.view')
            && Gate::forUser($actor)->allows('view', $inspection->tree);
    }

    public function update(User $actor, TreeInspection $inspection): bool
    {
        return $this->sameContext($actor, $inspection->organization_id) && !$inspection->tree->trashed()
            && $this->access->allows($actor, 'inspections.update');
    }

    public function delete(User $actor, TreeInspection $inspection): bool
    {
        return false;
    }

    public function forceDelete(User $actor, TreeInspection $inspection): bool
    {
        return false;
    }

    private function sameContext(User $actor, string $organizationId): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $organizationId;
    }
}
