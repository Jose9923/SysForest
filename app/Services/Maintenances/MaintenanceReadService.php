<?php

declare(strict_types=1);

namespace App\Services\Maintenances;

use App\Enums\Maintenances\MaintenancePriority;
use App\Enums\Maintenances\MaintenanceStatus;
use App\Models\Maintenances\Maintenance;
use App\Models\Trees\Tree;
use App\Models\User;
use App\Repositories\Maintenances\MaintenanceReferenceRepository;
use App\Repositories\Maintenances\MaintenanceRepository;
use App\Repositories\Trees\TreeRepository;
use App\Validation\Maintenances\MaintenanceInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Services\Evidences\EvidenceReadService;
use App\Services\Forestry\ForestryAccessService;

/** Prepara consultas, referencias y acciones permitidas sin consultas implícitas desde Blade. */
class MaintenanceReadService
{
    public function __construct(
        private ForestryAccessService $access,
        private MaintenanceRepository $maintenances,
        private MaintenanceReferenceRepository $references,
        private MaintenanceWorkflowService $workflow,
        private TreeRepository $trees,
        private EvidenceReadService $evidences,
    ) {
    }

    public function index(User $actor, array $input = []): array
    {
        $organization = $this->access->organization($actor);
        Gate::forUser($actor)->authorize('viewAny', Maintenance::class);
        Gate::forUser($actor)->authorize('viewAny', Tree::class);
        $filters = Validator::make(MaintenanceInput::normalize($input), MaintenanceInput::filters(), MaintenanceInput::messages())->validate();
        if (!empty($filters['trashed'])) {
            $this->access->authorize($actor, 'maintenances.restore');
        }
        if (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages(['date_to' => 'La fecha final no puede preceder a la inicial.']);
        }
        $tree = null;
        if (!empty($filters['tree_id'])) {
            $tree = $this->trees->findForTenant($organization->id, $filters['tree_id'], false, $this->access->allows($actor, 'trees.restore'));
            Gate::forUser($actor)->authorize('view', $tree);
        }

        return $this->labels() + [
            'organization' => $organization,
            'maintenances' => $this->maintenances->paginate($organization->id, $filters + ['include_voided_trees' => $this->access->allows($actor, 'trees.restore')])->through(fn (Maintenance $record) => $this->record($record)),
            'filters' => $filters,
            'options' => $this->references->optionsForFilter($organization->id),
            'selectedTree' => $tree,
            'canCreate' => $tree ? Gate::forUser($actor)->allows('create', [Maintenance::class, $tree]) : $this->access->allows($actor, 'maintenances.create'),
            'trashedAllowed' => $this->access->allows($actor, 'maintenances.restore'),
        ];
    }

    public function form(User $actor, string $treeId, ?string $maintenanceId = null): array
    {
        $organization = $this->access->organization($actor);
        $maintenance = $maintenanceId ? $this->authorizeExisting($actor, $maintenanceId, 'update') : null;
        $tree = $this->trees->findForTenant($organization->id, $treeId);
        abort_if($maintenance !== null && $maintenance->tree_id !== $tree->id, 404);
        Gate::forUser($actor)->authorize($maintenance ? 'update' : 'create', $maintenance ?? [Maintenance::class, $tree]);

        return $this->labels() + ['organization' => $organization, 'tree' => $tree, 'maintenance' => $maintenance,
            'options' => $this->references->options($organization->id, $maintenance)];
    }

    public function edit(User $actor, string $id): array
    {
        $maintenance = $this->authorizeExisting($actor, $id, 'update');

        return $this->form($actor, $maintenance->tree_id, $id);
    }

    public function show(User $actor, string $id): array
    {
        $maintenance = $this->authorizeExisting($actor, $id);
        $maintenance = $this->maintenances->detail($maintenance->organization_id, $maintenance->id, $maintenance->trashed());
        $actions = [];
        foreach ($this->workflow->allowedTransitions($maintenance) as $destination) {
            $ability = $destination === 'verified' ? 'verify' : 'transition';
            if (Gate::forUser($actor)->allows($ability, $maintenance)) {
                $action = $this->transitionAction($maintenance, $destination);
                if ($action['isPrimary']) {
                    array_unshift($actions, $action);
                } else {
                    $actions[] = $action;
                }
            }
        }
        $statusHistory = $maintenance->statusHistories->map(fn ($entry) => [
            'from' => $entry->old_status?->label(),
            'to' => $entry->new_status->label(),
            'reason' => $entry->reason,
            'changedAt' => $entry->changed_at->utc()->format('d/m/Y H:i').' UTC',
            'changedBy' => $entry->changedBy?->name ?? 'No disponible',
            'version' => $entry->version,
        ]);
        $revisions = $this->revisions($maintenance);

        return $this->labels() + [
            'organization' => $this->access->organization($actor), 'tree' => $maintenance->tree,
            'maintenance' => $maintenance, 'record' => $this->record($maintenance),
            'options' => $this->references->options($maintenance->organization_id),
            'transitionActions' => $actions, 'statusHistory' => $statusHistory, 'revisions' => $revisions,
            'progressGuide' => $this->progressGuide($maintenance, $actions),
            'canUpdate' => Gate::forUser($actor)->allows('update', $maintenance),
            'canVoid' => Gate::forUser($actor)->allows('void', $maintenance),
            'canRestore' => Gate::forUser($actor)->allows('restore', $maintenance),
            'maintenanceEvidenceGallery' => $this->evidences->gallery($actor, $maintenance),
        ];
    }

    /** Orienta el siguiente paso sin ampliar las transiciones autorizadas por el flujo y la política. */
    private function transitionAction(Maintenance $maintenance, string $destination): array
    {
        $primary = match ($maintenance->status) {
            MaintenanceStatus::Pending, MaintenanceStatus::Scheduled => 'assigned',
            MaintenanceStatus::Assigned => 'in_progress',
            MaintenanceStatus::InProgress => 'executed',
            MaintenanceStatus::Executed => 'verified',
            default => null,
        };
        [$label, $description] = match ($destination) {
            'scheduled' => ['Programar fecha', 'Elige la fecha prevista para la intervención. Al programar se retira la asignación actual de responsable.'],
            'assigned' => ['Asignar responsable', 'Selecciona la persona que realizará la intervención. Después podrás registrar el inicio del trabajo.'],
            'in_progress' => ['Iniciar ejecución', 'Confirma que el responsable ha comenzado el trabajo. El siguiente paso será registrar la fecha y el resultado.'],
            'executed' => ['Registrar ejecución', 'Indica cuándo terminó el trabajo y describe el resultado. La orden quedará Ejecutada, pendiente de verificación administrativa.'],
            'verified' => ['Verificar y cerrar mantenimiento', 'Revisa la fecha, el resultado y las evidencias. Al confirmar, la orden quedará Verificada y su seguimiento estará cerrado.'],
            'cancelled' => ['Cancelar trabajo', 'Utiliza esta opción si la intervención no se realizará. Explica el motivo; se conservará el historial.'],
        };

        return [
            'status' => $destination, 'label' => $label, 'description' => $description,
            'isPrimary' => $destination === $primary,
            'needsSchedule' => $destination === 'scheduled',
            'needsAssignee' => $destination === 'assigned',
            'needsResult' => $destination === 'executed',
            'needsReason' => $destination === 'cancelled',
            'isDestructive' => $destination === 'cancelled',
        ];
    }

    /** Distingue ejecución y cierre administrativo, incluso cuando el perfil solo puede consultar. */
    private function progressGuide(Maintenance $maintenance, array $actions): array
    {
        if ($maintenance->trashed() || $maintenance->tree->trashed()) {
            return ['title' => 'Seguimiento conservado para consulta',
                'description' => 'El registro o su árbol está anulado. No hay cambios de estado disponibles.'];
        }
        if ($maintenance->status === MaintenanceStatus::Verified) {
            return ['title' => 'Mantenimiento cerrado y verificado',
                'description' => 'La ejecución fue revisada y confirmada. El resultado, las evidencias y el historial permanecen disponibles.'];
        }
        if ($maintenance->status === MaintenanceStatus::Cancelled) {
            return ['title' => 'Mantenimiento cancelado',
                'description' => 'El seguimiento terminó por cancelación. Esta orden no acredita una intervención ejecutada y verificada.'];
        }
        if ($maintenance->status === MaintenanceStatus::Executed) {
            return count($actions) > 0
                ? ['title' => 'Revisa el resultado y confirma el cierre',
                    'description' => 'El trabajo ya está registrado como Ejecutado. Comprueba la fecha, el resultado y las evidencias antes de verificar y cerrar.']
                : ['title' => 'Pendiente de verificación administrativa',
                    'description' => 'La ejecución está registrada. Para cerrar el mantenimiento, un administrador de la organización o superadministrador debe revisar el resultado y verificarlo.'];
        }
        if (count($actions) === 0) {
            return ['title' => 'Seguimiento de la intervención',
                'description' => 'Tu perfil no dispone de cambios de estado para esta intervención. El cierre requiere registrar la ejecución y su posterior verificación administrativa.'];
        }

        return ['title' => 'Siguiente paso: '.$actions[0]['label'],
            'description' => 'Para completar el mantenimiento: asigna un responsable, inicia la ejecución, registra fecha y resultado y solicita la verificación administrativa.'];
    }

    public function treeSummary(User $actor, Tree $tree): array
    {
        $this->access->organization($actor);
        Gate::forUser($actor)->authorize('view', $tree);
        $canView = Gate::forUser($actor)->allows('viewAny', Maintenance::class);

        return [
            'canViewMaintenance' => $canView,
            'canCreateMaintenance' => Gate::forUser($actor)->allows('create', [Maintenance::class, $tree]),
            'maintenanceTimeline' => $canView
                ? $this->maintenances->timelineForTree($tree->organization_id, $tree->id)->map(fn (Maintenance $record) => $this->record($record))
                : collect(),
        ];
    }

    public function authorizeTree(User $actor, string $treeId): bool
    {
        $organization = $this->access->organization($actor);
        $tree = $this->trees->findForTenant($organization->id, $treeId);
        Gate::forUser($actor)->authorize('create', [Maintenance::class, $tree]);

        return true;
    }

    public function authorizeExisting(User $actor, string $id, string $ability = 'view'): Maintenance
    {
        $organization = $this->access->organization($actor);
        $maintenance = $this->maintenances->findForTenant($organization->id, $id, false, $this->access->allows($actor, 'maintenances.restore'));
        Gate::forUser($actor)->authorize($ability, $maintenance);

        return $maintenance;
    }

    private function record(Maintenance $maintenance): array
    {
        return [
            'id' => $maintenance->id, 'code' => $maintenance->code, 'treeId' => $maintenance->tree_id,
            'treeCode' => $maintenance->tree->code, 'treeVoided' => $maintenance->tree->trashed(),
            'typeName' => $maintenance->maintenanceType->name, 'typeActive' => $maintenance->maintenanceType->active,
            'status' => $maintenance->status->value, 'statusLabel' => $maintenance->status->label(),
            'priority' => $maintenance->priority->value, 'priorityLabel' => $maintenance->priority->label(),
            'assigneeName' => $maintenance->assignee?->name,
            'scheduledDate' => $maintenance->scheduled_date?->format('d/m/Y'),
            'performedDate' => $maintenance->performed_date?->format('d/m/Y'),
            'createdAt' => $maintenance->created_at->utc()->format('d/m/Y H:i').' UTC',
            'updatedAt' => $maintenance->updated_at->utc()->format('d/m/Y H:i').' UTC',
            'createdBy' => $maintenance->creator?->name ?? 'No disponible',
            'updatedBy' => $maintenance->updater?->name ?? 'No disponible',
            'notes' => $maintenance->notes, 'result' => $maintenance->result,
            'isVoided' => $maintenance->trashed(), 'deletionNotes' => $maintenance->deletion_notes,
            'deletedAt' => $maintenance->deleted_at?->utc()->format('d/m/Y H:i'),
            'deletedBy' => $maintenance->deleter?->name,
            'url' => route('maintenances.show', $maintenance->id),
        ];
    }

    /** Consulta referencias en lote y convierte snapshots en diferencias legibles. */
    private function revisions(Maintenance $maintenance): \Illuminate\Support\Collection
    {
        $types = [];
        $users = [];
        foreach ($maintenance->revisions as $revision) {
            foreach ([$revision->old_values, $revision->new_values] as $snapshot) {
                if (isset($snapshot['maintenance_type_id'])) {
                    $types[] = $snapshot['maintenance_type_id'];
                }
                foreach (['assigned_to', 'deleted_by'] as $field) {
                    if (isset($snapshot[$field])) {
                        $users[] = $snapshot[$field];
                    }
                }
            }
        }
        $references = $this->references->historyLabels(array_values(array_unique($types)), array_values(array_unique($users)));
        $fields = [
            'maintenance_type_id' => 'Tipo de mantenimiento', 'status' => 'Estado del trabajo',
            'priority' => 'Prioridad', 'scheduled_date' => 'Fecha programada',
            'performed_date' => 'Fecha de ejecución', 'assigned_to' => 'Responsable',
            'notes' => 'Notas de la intervención', 'result' => 'Resultado de la ejecución',
            'deleted_at' => 'Estado del registro', 'deleted_by' => 'Anulado por',
            'deletion_notes' => 'Motivo de anulación',
        ];

        return $maintenance->revisions->map(function ($revision) use ($references, $fields): array {
            $differences = [];
            foreach ($fields as $field => $label) {
                $before = $this->revisionValue($field, $revision->old_values[$field] ?? null, $references);
                $after = $this->revisionValue($field, $revision->new_values[$field] ?? null, $references);
                if (($revision->old_values[$field] ?? null) !== ($revision->new_values[$field] ?? null)) {
                    $differences[] = ['label' => $label, 'before' => $before, 'after' => $after];
                }
            }

            return [
                'version' => $revision->version,
                'eventLabel' => match ($revision->event) {
                    'maintenance.created' => 'Mantenimiento registrado',
                    'maintenance.updated' => 'Datos actualizados',
                    'maintenance.status_changed' => 'Estado actualizado',
                    'maintenance.voided' => 'Registro anulado',
                    'maintenance.restored' => 'Registro restaurado',
                    default => 'Cambio registrado',
                },
                'changedAt' => $revision->changed_at->utc()->format('d/m/Y H:i').' UTC',
                'changedBy' => $revision->changedBy?->name ?? 'No disponible',
                'differences' => $differences,
            ];
        });
    }

    private function revisionValue(string $field, mixed $value, array $references): string
    {
        if ($field === 'deleted_at') {
            return $value === null ? 'Vigente' : 'Anulado el '.\Carbon\CarbonImmutable::parse($value)->utc()->format('d/m/Y H:i').' UTC';
        }
        if ($value === null) {
            return 'No informado';
        }

        return match ($field) {
            'maintenance_type_id' => $references['types'][$value] ?? 'Tipo no disponible',
            'assigned_to', 'deleted_by' => $references['users'][$value] ?? 'Persona no disponible',
            'status' => MaintenanceStatus::tryFrom((string) $value)?->label() ?? 'Estado no disponible',
            'priority' => MaintenancePriority::tryFrom((string) $value)?->label() ?? 'Prioridad no disponible',
            'scheduled_date', 'performed_date' => \Carbon\CarbonImmutable::parse($value)->format('d/m/Y'),
            default => (string) $value,
        };
    }

    private function labels(): array
    {
        return [
            'statuses' => collect(MaintenanceStatus::cases())->mapWithKeys(fn (MaintenanceStatus $status) => [$status->value => $status->label()])->all(),
            'priorities' => collect(MaintenancePriority::cases())->mapWithKeys(fn (MaintenancePriority $priority) => [$priority->value => $priority->label()])->all(),
        ];
    }
}
