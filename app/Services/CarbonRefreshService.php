<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RefreshCarbonEstimates;
use App\Models\CarbonRefreshRun;
use App\Models\User;
use App\Repositories\CarbonRefreshRepository;
use App\Repositories\CarbonRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** El cursor y cada estimación avanzan juntos; ningún bloqueo abarca el historial completo. */
class CarbonRefreshService
{
    public function __construct(
        private CarbonRefreshRepository $runs,
        private CarbonRepository $carbon,
        private CarbonEstimationService $estimates,
        private UserRepository $users,
        private ForestryAccessService $access,
        private TenantContextService $tenants,
        private SecurityEventRepository $events,
    ) {
    }

    public function available(): bool
    {
        return $this->runs->available();
    }

    public function request(User $actor): CarbonRefreshRun
    {
        if (!$this->available()) {
            throw ValidationException::withMessages(['refresh' => 'La actualización en segundo plano requiere instalar la migración de progreso de carbono.']);
        }
        $run = DB::transaction(function () use ($actor): CarbonRefreshRun {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $this->access->authorize($actor, 'carbon.manage');
            $this->carbon->lockOrganization($organization->id);
            $existing = $this->runs->active($organization->id);
            if ($existing !== null) {
                return $existing;
            }
            $run = $this->runs->create($organization->id, (int) $actor->id, now());
            $this->audit($run, 'requested');

            return $run;
        }, 3);
        // No se ejecuta el recálculo en la petición HTTP, incluso si otro entorno configura sync.
        $this->dispatch($run->id);

        return $run;
    }

    public function latest(User $actor): ?array
    {
        $organization = $this->access->organization($actor);
        $this->access->authorize($actor, 'inspections.view');
        $run = $this->available() ? $this->runs->latest($organization->id) : null;

        return $run === null ? null : $this->present($run);
    }

    public function processBatch(string $id): bool
    {
        $deadline = microtime(true) + 15;
        for ($step = 0; $step < 25 && microtime(true) < $deadline; $step++) {
            $run = $this->runs->find($id);
            if ($run === null || !$run->isActive()) {
                return false;
            }
            $more = DB::transaction(function () use ($run): bool {
                // Orden compartido con las escrituras web: usuario, institución, ejecución, visita.
                $actor = $this->users->findForUpdate($run->requested_by);
                $this->carbon->lockOrganization($run->organization_id);
                $current = $this->runs->lock($run->id);
                if ($current === null || !$current->isActive()) {
                    return false;
                }
                try {
                    return $this->tenants->run($actor, $current->organization_id, function () use ($actor, $current): bool {
                        $this->access->organization($actor, true);
                        $this->access->authorize($actor, 'carbon.manage');
                        if ($this->runs->referenceVersion($current->organization_id) !== $current->reference_version) {
                            $this->finish($current, 'cancelled', 'references_changed');

                            return false;
                        }
                        $inspectionId = $this->runs->nextInspection($current);
                        if ($inspectionId === null) {
                            $this->finish($current, 'completed');

                            return false;
                        }
                        $inspection = $this->carbon->lockInspection($current->organization_id, $inspectionId);
                        $added = $this->estimates->record($inspection);
                        $this->runs->update($current, ['status' => 'running', 'last_inspection_id' => $inspectionId,
                            'processed' => $current->processed + 1, 'estimates_added' => $current->estimates_added + (int) $added]);
                        if ($current->processed >= $current->total) {
                            $this->finish($current, 'completed');

                            return false;
                        }

                        return true;
                    });
                } catch (AuthorizationException) {
                    $this->finish($current, 'cancelled', 'access_revoked');

                    return false;
                }
            }, 3);
            if (!$more) {
                return false;
            }
        }

        return true;
    }

    public function dispatch(string $id, bool $continuation = false): void
    {
        if (!$this->runs->claimDispatch($id, $continuation)) {
            return;
        }
        try {
            RefreshCarbonEstimates::dispatch($id)->onConnection('redis');
        } catch (Throwable) {
            // El scheduler retoma solicitudes durables; nunca se pierden por indisponibilidad de la cola.
        }
    }

    public function dispatchPending(): int
    {
        if (!$this->available()) {
            return 0;
        }
        $ids = $this->runs->due();
        foreach ($ids as $id) {
            $this->dispatch($id);
        }

        return $ids->count();
    }

    public function failed(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $run = $this->runs->lock($id);
            if ($run !== null && $run->isActive()) {
                $this->finish($run, 'failed', 'processing_failed');
            }
        }, 3);
    }

    private function finish(CarbonRefreshRun $run, string $status, ?string $reason = null): void
    {
        $this->runs->update($run, ['status' => $status, 'reason' => $reason, 'finished_at' => now()]);
        $this->audit($run, $status);
    }

    private function audit(CarbonRefreshRun $run, string $action): void
    {
        $this->events->record(
            'carbon.refresh.'.$action,
            organizationId: $run->organization_id,
            actor: $this->users->find($run->requested_by),
            resourceType: 'carbon_refresh_run',
            resourceId: $run->id,
            beforeValues: [],
            afterValues: ['configuration_json' => json_encode(['status' => $run->status, 'reason' => $run->reason,
                'reference_version' => $run->reference_version, 'total' => $run->total,
                'processed' => $run->processed, 'estimates_added' => $run->estimates_added], JSON_THROW_ON_ERROR)],
        );
    }

    private function present(CarbonRefreshRun $run): array
    {
        $labels = ['queued' => 'En cola', 'running' => 'En proceso', 'completed' => 'Completado',
            'cancelled' => 'Cancelado', 'failed' => 'Fallido'];
        $message = match ($run->reason) {
            'references_changed' => 'Las referencias cambiaron. Solicita otra actualización para aplicar la nueva versión a todas las visitas.',
            'access_revoked' => 'El solicitante perdió acceso a esta institución. Un administrador autorizado puede solicitar otra actualización.',
            'processing_failed' => 'No se pudo completar tras los reintentos. Puedes volver a solicitar; las estimaciones confirmadas se conservan.',
            default => $run->isActive()
                ? 'Puedes continuar trabajando. El progreso se actualiza automáticamente.'
                : 'Se conservaron los resultados anteriores. Las visitas nuevas se calculan al guardarlas.',
        };

        return ['id' => $run->id, 'status' => $run->status, 'label' => $labels[$run->status],
            'active' => $run->isActive(), 'total' => $run->total, 'processed' => $run->processed,
            'estimates_added' => $run->estimates_added, 'message' => $message,
            'requested_at' => $run->requested_at->toIso8601String()];
    }
}
