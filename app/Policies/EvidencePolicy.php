<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Evidence;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Services\ForestryAccessService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/** Los permisos de evidencia siempre se intersectan con el acceso al propietario institucional. */
class EvidencePolicy
{
    public function __construct(private ForestryAccessService $access, private TenantContext $context)
    {
    }

    public function viewAny(User $actor): bool
    {
        return $this->access->allows($actor, 'evidences.view');
    }

    public function create(User $actor, Tree|TreeInspection $owner): Response
    {
        $root = $owner instanceof Tree ? $owner : $owner->tree;
        $allowed = $this->sameContext($actor, $owner->organization_id) && !$root->trashed()
            && $this->access->allows($actor, 'evidences.create') && Gate::forUser($actor)->allows('view', $owner);

        return $allowed ? Response::allow() : Response::deny('No tienes permiso para adjuntar evidencias a este registro.');
    }

    public function view(User $actor, Evidence $evidence): bool
    {
        return $this->sameContext($actor, $evidence->organization_id)
            && $this->access->allows($actor, 'evidences.view')
            && (!$evidence->trashed() || $this->access->allows($actor, 'evidences.restore'))
            && Gate::forUser($actor)->allows('view', $this->owner($evidence));
    }

    public function update(User $actor, Evidence $evidence): bool
    {
        $owner = $this->owner($evidence);
        $root = $owner instanceof Tree ? $owner : $owner->tree;

        return $this->view($actor, $evidence) && !$evidence->trashed() && !$root->trashed()
            && $this->access->allows($actor, 'evidences.update');
    }

    public function delete(User $actor, Evidence $evidence): bool
    {
        return $this->view($actor, $evidence) && !$evidence->trashed() && $this->access->allows($actor, 'evidences.delete');
    }

    public function restore(User $actor, Evidence $evidence): bool
    {
        return $this->view($actor, $evidence) && $evidence->trashed() && $this->access->allows($actor, 'evidences.restore');
    }

    public function forceDelete(User $actor, Evidence $evidence): bool
    {
        return false;
    }

    private function owner(Evidence $evidence): Tree|TreeInspection
    {
        return $evidence->tree_id !== null ? $evidence->tree : $evidence->inspection;
    }

    private function sameContext(User $actor, string $organizationId): bool
    {
        return $this->context->isActive() && $this->context->actorId() === (int) $actor->id
            && $this->context->organizationId() === $organizationId;
    }
}
