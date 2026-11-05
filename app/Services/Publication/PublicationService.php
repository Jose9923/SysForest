<?php

declare(strict_types=1);

namespace App\Services\Publication;

use App\Enums\Inventories\InventoryStatus;
use App\Enums\Maintenances\MaintenanceStatus;
use App\Exceptions\Shared\StaleRecordException;
use App\Models\Evidences\Evidence;
use App\Models\Inventories\Inventory;
use App\Models\Organizations\Organization;
use App\Models\Trees\Tree;
use App\Models\Inspections\TreeInspection;
use App\Models\User;
use App\Repositories\Publication\PublicationRepository;
use App\Repositories\Users\UserRepository;
use App\Validation\Publication\PublicationInput;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\Inspections\InspectionDerivedResultService;

/** Publicación explícita. La cadena completa se evalúa de nuevo en cada consulta anónima. */
class PublicationService
{
    public function __construct(
        private PublicationAccessService $access,
        private PublicationRepository $records,
        private UserRepository $users,
        private PublicationAuditService $audit,
        private InspectionDerivedResultService $derivedResults,
    ) {
    }

    public function organization(User $actor, array $input): Organization
    {
        return $this->run($actor, $input, fn (Organization $organization) => $organization);
    }

    public function inventory(User $actor, string $id, array $input): Inventory
    {
        return $this->run($actor, $input, fn (Organization $organization) => $this->records->inventory($organization->id, $id, true));
    }

    public function tree(User $actor, string $id, array $input): Tree
    {
        return $this->run($actor, $input, fn (Organization $organization) => $this->records->tree($organization->id, $id, true));
    }

    public function inspection(User $actor, string $id, array $input): TreeInspection
    {
        return $this->run($actor, $input, function (Organization $organization) use ($id): TreeInspection {
            $reference = $this->records->inspection($organization->id, $id);
            $tree = $this->records->tree($organization->id, $reference->tree_id, true);
            $record = $this->records->inspection($organization->id, $id, true);
            $record->setRelation('tree', $tree);

            return $record;
        });
    }

    public function evidence(User $actor, string $id, array $input): Evidence
    {
        return $this->run($actor, $input, function (Organization $organization) use ($id): Evidence {
            $reference = $this->records->evidence($organization->id, $id);
            $treeId = $reference->tree_id ?? $reference->inspection?->tree_id ?? $reference->maintenance?->tree_id;
            $tree = $this->records->tree($organization->id, $treeId, true);
            if ($reference->tree_inspection_id !== null) {
                $owner = $this->records->inspection($organization->id, $reference->tree_inspection_id, true);
                $owner->setRelation('tree', $tree);
                $relation = 'inspection';
            } elseif ($reference->maintenance_id !== null) {
                $owner = $this->records->maintenance($organization->id, $reference->maintenance_id, true);
                $owner->setRelation('tree', $tree);
                $relation = 'maintenance';
            } else {
                $owner = $tree;
                $relation = 'tree';
            }
            $record = $this->records->evidence($organization->id, $id, true);
            $record->setRelation($relation, $owner);

            return $record;
        });
    }

    private function run(User $actor, array $input, Closure $resolve): Model
    {
        try {
            return DB::transaction(function () use ($actor, $input, $resolve): Model {
                $actor = $this->users->findForUpdate((int) $actor->id);
                $organization = $this->access->organization($actor, true);
                $record = $resolve($organization);
                $data = Validator::make(
                    $input,
                    PublicationInput::rules($record instanceof Evidence),
                    PublicationInput::messages()
                )->validate();
                if ((int) $data['publication_version'] !== $record->publication_version) {
                    throw new StaleRecordException();
                }
                $published = (bool) $data['is_public'];
                if ($published) {
                    $this->validatePublication($record);
                }
                $changes = ['is_public' => $published];
                if ($record instanceof Evidence) {
                    // Nunca copiar caption privado; el texto público se proporciona expresamente.
                    $changes['public_caption'] = array_key_exists('public_caption', $data)
                        ? $data['public_caption'] : $record->public_caption;
                    if ($published && $record->public_id === null) {
                        $changes['public_id'] = (string) Str::ulid();
                    }
                }
                if ($record instanceof Organization && $published && $record->public_code === null) {
                    $changes['public_code'] = $record->code;
                }
                if ($published === $record->is_public
                    && (!($record instanceof Evidence) || ($changes['public_caption'] ?? null) === $record->public_caption)) {
                    return $record;
                }
                $old = $this->audit->snapshot($record);
                $changes['publication_version'] = $record->publication_version + 1;
                if (!$record instanceof Organization) {
                    if ($record->lock_version >= 2147483647) {
                        throw new StaleRecordException();
                    }
                    $changes += ['lock_version' => $record->lock_version + 1, 'updated_by' => $actor->id];
                }
                $previousVersion = $record instanceof TreeInspection ? $record->lock_version : null;
                $this->records->persist($record, $changes);
                if ($record instanceof TreeInspection) {
                    $this->derivedResults->preserveAfterPublication($record, $previousVersion);
                }
                $this->audit->record($actor, $record, $old);

                return $record;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'organizations_public_code_unique')) {
                throw ValidationException::withMessages(['is_public' => 'Este código institucional ya está reservado por otra URL pública. Cambia el código administrativo antes de publicar por primera vez.']);
            }
            throw $exception;
        }
    }

    private function validatePublication(Organization|Inventory|Tree|TreeInspection|Evidence $record): void
    {
        if ($record instanceof Inventory && (!$record->active || $record->status === InventoryStatus::Draft)) {
            $this->invalid('Solo puede publicarse una campaña disponible que haya salido del estado Borrador.');
        }
        if (($record instanceof Tree || $record instanceof Evidence) && $record->trashed()) {
            $this->invalid('Un registro anulado no puede publicarse.');
        }
        if ($record instanceof TreeInspection && $record->tree->trashed()) {
            $this->invalid('La inspección pertenece a un árbol anulado.');
        }
        if ($record instanceof Evidence) {
            $owner = $record->ownerRecord();
            $tree = $owner instanceof Tree ? $owner : $owner->tree;
            if ($tree->trashed()) {
                $this->invalid('La fotografía pertenece a un árbol anulado.');
            }
            if ($record->maintenance_id !== null && ($owner->trashed()
                || !in_array($owner->status, [MaintenanceStatus::Executed, MaintenanceStatus::Verified], true))) {
                $this->invalid('Solo pueden publicarse fotos de mantenimientos ejecutados o verificados y no anulados.');
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['is_public' => $message]);
    }
}
