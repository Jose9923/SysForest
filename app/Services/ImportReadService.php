<?php

declare(strict_types=1);

namespace App\Services;

use App\Catalogs\CatalogRegistry;
use App\Enums\ImportBatchStatus;
use App\Imports\ImportTemplateSchema;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\ImportRowRepository;
use App\Repositories\InventoryRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ImportReadService
{
    private const DISPLAY_CALCULATIONS = [
        'dap_m', 'radius_m', 'basal_area_m2', 'commercial_volume_m3', 'total_volume_m3',
        'diameter_class_number', 'height_class_number',
    ];
    public function __construct(
        private ImportAccessService $access,
        private ImportBatchRepository $batches,
        private ImportRowRepository $rows,
        private InventoryRepository $inventories,
        private ImportTemplateSchema $schema,
        private CatalogRepository $catalogs,
        private CatalogRegistry $registry,
    ) {
    }

    public function index(User $actor): array
    {
        $organization = $this->access->authorize($actor);

        return ['organization' => $organization, 'batches' => $this->batches->paginate($organization->id),
            'canCreate' => $this->access->allows($actor, 'imports.create')];
    }

    public function form(User $actor): array
    {
        $organization = $this->access->authorize($actor, 'imports.create');

        return ['organization' => $organization, 'inventories' => $this->inventories->options($organization->id, true),
            'limits' => ['max_file_mb' => (int) config('imports.max_upload_kb') / 1024, 'max_rows' => (int) config('imports.max_rows')]];
    }

    public function show(User $actor, string $id): array
    {
        $organization = $this->access->authorize($actor);
        $batch = $this->batches->find($organization->id, $id);
        $canWrite = $this->access->allows($actor, 'imports.create');
        $rows = $this->rows->paginate($batch);
        $definitions = $this->schema->columns();

        return ['organization' => $organization, 'batch' => $batch,
            'inventory' => $this->inventories->findForTenant($organization->id, $batch->inventory_id),
            'rows' => $rows, 'fieldDefinitions' => $definitions,
            'calculatedFields' => $this->calculatedFields($rows, $definitions),
            'reviewExpired' => in_array($batch->status, [ImportBatchStatus::Ready, ImportBatchStatus::Invalid], true) && $batch->expires_at->isPast(),
            'rowStatusLabels' => ['valid' => 'Válida', 'invalid' => 'Con errores', 'imported' => 'Importada'],
            'canConfirm' => $canWrite && $batch->status === ImportBatchStatus::Ready && $batch->expires_at->isFuture(),
            'canCancel' => $canWrite && in_array($batch->status, [ImportBatchStatus::Ready, ImportBatchStatus::Invalid], true)];
    }

    /**
     * La vista recibe medidas y etiquetas; los UUID y versiones siguen en el lote para auditoría y revalidación.
     * Cada estado de madurez se resuelve una vez por página, conservando su lectura aunque esté inactivo.
     *
     * @return array<int,list<array{label:string,value:mixed}>>
     */
    private function calculatedFields(iterable $rows, array $definitions): array
    {
        $fields = [];
        $maturityNames = [];
        foreach ($rows as $row) {
            $calculations = $row->normalized_values['calculations'] ?? [];
            if ($calculations === []) {
                continue;
            }
            $display = [];
            foreach (self::DISPLAY_CALCULATIONS as $key) {
                $display[] = ['label' => $definitions[$key]['label'], 'value' => $calculations[$key] ?? null];
            }
            $maturityId = $calculations['maturity_state_id'] ?? null;
            $maturityName = null;
            if (is_string($maturityId) && $maturityId !== '') {
                if (!array_key_exists($maturityId, $maturityNames)) {
                    try {
                        $maturityNames[$maturityId] = $this->catalogs->find($this->registry->find('maturity-states'), $maturityId)->name;
                    } catch (ModelNotFoundException) {
                        $maturityNames[$maturityId] = 'Referencia de madurez no disponible';
                    }
                }
                $maturityName = $maturityNames[$maturityId];
            }
            $display[] = ['label' => $definitions['maturity_state']['label'], 'value' => $maturityName];
            $fields[$row->row_number] = $display;
        }

        return $fields;
    }
}
