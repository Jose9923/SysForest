<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleEvidenceException;
use App\Models\Evidence;
use App\Models\Tree;
use App\Models\User;
use App\Repositories\EvidenceRepository;
use App\Repositories\UserRepository;
use App\Validation\EvidenceInput;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Orden común de bloqueo: actor, organización, árbol, inspección, evidencia. */
class EvidenceWriteService
{
    public function __construct(
        private UserRepository $users,
        private ForestryAccessService $access,
        private EvidenceOwnerService $owners,
        private EvidenceRepository $evidences,
        private EvidenceAuditService $audit
    ) {
    }

    public function run(User $actor, string $id, string $ability, array $input, Closure $operation, string $event): Evidence
    {
        return DB::transaction(function () use ($actor, $id, $ability, $input, $operation, $event): Evidence {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $reference = $this->evidences->findForTenant($organization->id, $id, false, true);
            $owner = $this->owners->resolve($actor, $reference->tree_id, $reference->tree_inspection_id, true);
            $evidence = $this->evidences->findForTenant($organization->id, $id, true, true);
            $evidence->setRelation($owner instanceof Tree ? 'tree' : 'inspection', $owner);
            Gate::forUser($actor)->authorize($ability, $evidence);
            $validated = Validator::make($input, EvidenceInput::versionRules(), EvidenceInput::messages())->validate();
            if ((int) $validated['lock_version'] !== $evidence->lock_version) {
                throw new StaleEvidenceException();
            }
            $old = $this->audit->snapshot($evidence);
            $operation($evidence, $actor, ['updated_by' => $actor->id, 'lock_version' => $evidence->lock_version + 1]);
            $evidence = $this->evidences->findForTenant($organization->id, $id, false, true);
            $this->audit->record($actor, $evidence, $old, $event);

            return $evidence;
        }, 3);
    }
}
