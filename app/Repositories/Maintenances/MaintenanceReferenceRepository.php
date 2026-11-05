<?php

declare(strict_types=1);

namespace App\Repositories\Maintenances;

use App\Models\Maintenances\Maintenance;
use App\Models\Catalogs\MaintenanceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MaintenanceReferenceRepository
{
    public function options(string $organizationId, ?Maintenance $current = null): array
    {
        if ($current !== null && $current->organization_id !== $organizationId) {
            throw new LogicException('La referencia histórica pertenece a otra organización.');
        }
        $types = MaintenanceType::query()->where(function (Builder $query) use ($current): void {
            $query->where('active', true);
            if ($current !== null) {
                $query->orWhereKey($current->maintenance_type_id);
            }
        })->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
        $assignees = User::query()->where(function (Builder $query) use ($organizationId, $current): void {
            $query->whereIn('id', $this->eligible($organizationId)->select('users.id'));
            if ($current?->assigned_to !== null) {
                $query->orWhereKey($current->assigned_to);
            }
        })->orderBy('name')->orderBy('id')->get();

        return ['types' => $types, 'assignees' => $assignees];
    }

    /**
     * API interna: los IDs proceden exclusivamente de revisiones de una orden ya autorizada.
     * No recibe selecciones arbitrarias del cliente; incluye nombres de referencias históricas inactivas.
     *
     * @return array{types: array<string, string>, users: array<int, string>}
     */
    public function historyLabels(array $typeIds, array $userIds): array
    {
        return [
            'types' => MaintenanceType::query()->whereIn('id', array_values(array_unique($typeIds)))->pluck('name', 'id')->all(),
            'users' => User::query()->whereIn('id', array_values(array_unique($userIds)))->pluck('name', 'id')->all(),
        ];
    }

    public function optionsForFilter(string $organizationId): array
    {
        return [
            'types' => MaintenanceType::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get(),
            'assignees' => User::query()->whereIn('id', DB::table('organization_memberships')
                ->where('organization_id', $organizationId)->select('user_id'))->orderBy('name')->orderBy('id')->get(),
        ];
    }

    public function validType(string $id, ?string $currentId = null, bool $lock = false): bool
    {
        if (!Str::isUuid($id)) {
            return false;
        }
        $query = MaintenanceType::query()->whereKey($id);
        if ($lock) {
            $this->requireTransaction();
            $query->sharedLock();
        }
        $type = $query->first();

        return $type !== null && ($type->active || strtolower($id) === strtolower($currentId ?? ''));
    }

    /** Bloquea referencias vigentes sin esperar a un actor que podría aguardar nuestra organización. */
    public function validAssignee(string $organizationId, int $userId, bool $lock = false): bool
    {
        if ($userId < 1) {
            return false;
        }
        $query = $this->eligible($organizationId)->where('users.id', $userId)->select('users.id');
        if ($lock) {
            $this->requireTransaction();
            $query->lock('FOR SHARE OF users, m, a, r NOWAIT');
        }
        try {
            return $query->first() !== null;
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '55P03') {
                throw new HttpException(409, 'No fue posible confirmar el responsable. Actualiza la página e inténtalo de nuevo.');
            }
            throw $exception;
        }
    }

    private function eligible(string $organizationId): Builder
    {
        return User::query()->join('organization_memberships as m', 'm.user_id', '=', 'users.id')
            ->join('organization_role_assignments as a', 'a.membership_id', '=', 'm.id')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('m.organization_id', $organizationId)->where('a.organization_id', $organizationId)
            ->where('users.active', true)->whereNotNull('users.email_verified_at')
            ->where('m.active', true)->whereNull('m.left_at')->where('a.active', true)->whereNull('a.revoked_at')
            ->where('r.scope', 'organization')->whereIn('r.name', ['OFFICIAL', 'ORGANIZATION_ADMIN']);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La validación bloqueante requiere una transacción.');
        }
    }
}
