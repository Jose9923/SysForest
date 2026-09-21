<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleMaintenanceException;
use App\Models\Maintenance;
use App\Models\User;
use App\Repositories\MaintenanceRepository;
use App\Repositories\TreeRepository;
use App\Repositories\UserRepository;
use App\Validation\MaintenanceInput;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Comparte el orden actor → organización → árbol → orden con el módulo de evidencias. */
class MaintenanceWriteService
{
    public function __construct(
        private UserRepository $users,
        private ForestryAccessService $access,
        private TreeRepository $trees,
        private MaintenanceRepository $maintenances,
        private MaintenanceAuditService $audit
    ) {
    }

    public function run(User $actor, string $id, string $ability, array $input, Closure $operation, string $event): Maintenance
    {
        return DB::transaction(function () use ($actor, $id, $ability, $input, $operation, $event): Maintenance {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $reference = $this->maintenances->findForTenant($organization->id, $id, false, true);
            $tree = $this->trees->findForTenant($organization->id, $reference->tree_id, true, true);
            $maintenance = $this->maintenances->findForTenant($organization->id, $id, true, true);
            $maintenance->setRelation('tree', $tree);
            Gate::forUser($actor)->authorize($ability, $maintenance);
            $data = Validator::make($input, MaintenanceInput::versionRules(), MaintenanceInput::messages())->validate();
            if ((int) $data['lock_version'] !== $maintenance->lock_version) {
                throw new StaleMaintenanceException();
            }
            $old = $this->audit->snapshot($maintenance);
            $operation($maintenance, $actor, ['updated_by' => $actor->id, 'lock_version' => $maintenance->lock_version + 1]);
            $maintenance = $this->maintenances->findForTenant($organization->id, $id, false, true);
            $this->audit->record($actor, $maintenance, $old, $event);

            return $maintenance;
        }, 3);
    }
}
