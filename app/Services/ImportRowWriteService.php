<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Reutiliza los servicios transaccionales existentes; nunca actualiza un individuo por coincidencia de código. */
class ImportRowWriteService
{
    public function __construct(
        private TreeCodeService $codes,
        private TreeService $trees,
        private TreeInspectionService $inspections,
    ) {
    }

    /** Reserva antes de las filas automáticas para que ninguna consuma un código explícito posterior. */
    public function reserveCodes(Organization $organization, array $validatedRows): void
    {
        $this->requireTransaction();
        $codes = [];
        $automaticCount = 0;
        foreach ($validatedRows as $row) {
            $this->requireValid($row);
            if (($row['requested_code'] ?? null) !== null) {
                $codes[] = $row['requested_code'];
            } else {
                $automaticCount++;
            }
        }
        $this->codes->reserveImported($organization, $codes, $automaticCount);
    }

    /** @return array{tree:\App\Models\Tree,inspection:\App\Models\TreeInspection} */
    public function create(User $actor, Inventory $inventory, array $validated): array
    {
        $this->requireTransaction();
        $this->requireValid($validated);
        $treeInput = $validated['tree'];
        // La campaña viene del lote autorizado; jamás de una celda o de datos normalizados editables.
        $treeInput['inventory_ids'] = [$inventory->id];
        $tree = $this->trees->createImported($actor, $treeInput, $validated['requested_code'] ?? null);
        if ($tree->organization_id !== $inventory->organization_id) {
            throw ValidationException::withMessages(['inventory_id' => 'El inventario no pertenece a la organización autorizada.']);
        }
        $participation = $tree->inventories->firstWhere('id', $inventory->id)?->pivot?->id;
        if ($participation === null) {
            throw new LogicException('La creación no produjo la participación institucional requerida.');
        }
        $inspectionInput = $validated['inspection'];
        $inspectionInput['inventory_tree_id'] = $participation;
        $origin = app(HistoricalRiskImportScope::class)->matches($inventory->organization_id, $inventory->id) ? 'historical' : 'import';
        $inspection = $this->inspections->create($actor, $tree->id, $inspectionInput, $origin);

        return ['tree' => $tree, 'inspection' => $inspection];
    }

    private function requireValid(array $row): void
    {
        if (($row['errors'] ?? []) !== []) {
            throw ValidationException::withMessages($row['errors']);
        }
        if (! isset($row['tree'], $row['inspection']) || ! is_array($row['tree']) || ! is_array($row['inspection'])) {
            throw new LogicException('La fila no tiene el contrato validado de importación.');
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('La escritura de importaciones requiere la transacción del lote completo.');
        }
    }
}
