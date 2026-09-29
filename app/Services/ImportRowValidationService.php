<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Imports\ImportTemplateSchema;
use App\Models\Inventory;
use App\Models\Organization;
use App\Repositories\ImportCatalogRepository;
use App\Validation\DendrometryInput;
use App\Validation\TreeInput;
use App\Validation\TreeInspectionInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Prevalida sin escrituras; la confirmación vuelve a resolver entradas y referencias vigentes. */
class ImportRowValidationService
{
    private const TREE_FIELDS = ['external_number', 'location_description', 'current_status',
        'is_protected', 'is_heritage', 'coordinate_raw', 'datum', 'accuracy_m', 'gps_brand', 'gps_model', 'captured_at'];
    private const REFERENCES = ['placement_code' => 'placement_id', 'species_code' => 'species_id',
        'growth_habit_code' => 'growth_habit_id', 'establishment_origin_code' => 'establishment_origin_id',
        'biogeographic_origin_code' => 'biogeographic_origin_id'];

    public function __construct(
        private ImportTemplateSchema $schema,
        private ImportCatalogRepository $catalogs,
        private TreeCodeService $codes,
        private TreeLocationService $locations,
        private DendrometryCalculationService $calculations,
        private PhenologyService $phenology,
    ) {
    }

    /**
     * @param array<string,mixed> $values Celdas del lector oficial, nunca datos normalizados del navegador.
     * @param array<string,list<string>> $readerErrors Conserva errores XML/fórmulas aunque la celda se haya descartado.
     * @return array{tree:array,inspection:array,requested_code:?string,calculations:array,errors:array}
     */
    public function validate(Organization $organization, Inventory $inventory, array $values, array $readerErrors = [], bool $lock = false): array
    {
        $errors = $readerErrors;
        $normalized = $this->cells($values, $errors);
        $status = $inventory->status instanceof InventoryStatus ? $inventory->status->value : $inventory->status;
        if ($inventory->organization_id !== $organization->id || ! $inventory->active || $status !== 'active') {
            $errors['inventory_id'][] = 'La importación requiere un inventario activo de la organización seleccionada.';
        }
        $tree = array_intersect_key($normalized, array_flip(self::TREE_FIELDS));
        $tree['inventory_ids'] = [$inventory->id];
        foreach (self::REFERENCES as $field => $target) {
            $tree[$target] = $this->reference($field, $normalized[$field] ?? null, $lock, $errors);
        }
        $inspectionFields = array_merge(
            DendrometryInput::FIELDS,
            TreeInspectionInput::TEXT_FIELDS,
            TreeInspectionInput::DATE_FIELDS,
            ['inspection_date']
        );
        $inspection = array_intersect_key($normalized, array_flip($inspectionFields));
        $inspection += ['physical_condition_ids' => [], 'phytosanitary_condition_ids' => [], 'fauna_category_ids' => []];
        $this->conditions($normalized, $inspection, $errors, $lock);
        $inspection['fauna_category_ids'] = $this->fauna($normalized['fauna_category_codes'] ?? null, $lock, $errors);
        $code = $normalized['code'] ?? null;
        if ($code !== null) {
            $this->capture(fn () => $this->codes->validateImported($organization, $code), $errors);
        }

        $this->capture(function () use (&$tree): void {
            $tree = Validator::make($tree, TreeInput::rules(), TreeInput::messages())->validate();
            $tree = array_replace($tree, $this->locations->validateInput($tree));
        }, $errors);
        if ($lock) {
            $this->catalogs->lockClassification();
        }
        $derived = [];
        $this->capture(function () use (&$inspection, &$derived): void {
            $inspection = Validator::make($inspection, TreeInspectionInput::rules(), TreeInspectionInput::messages())->validate();
            $inspection = array_replace($inspection, DendrometryInput::validate($inspection), $this->phenology->normalize($inspection));
            $derived = $this->calculations->calculate($inspection);
        }, $errors);

        return ['tree' => $tree, 'inspection' => $inspection, 'requested_code' => $code,
            'calculations' => $derived, 'errors' => $this->uniqueErrors($errors)];
    }

    private function cells(array $values, array &$errors): array
    {
        $columns = $this->schema->columns();
        foreach (array_diff(array_keys($values), array_keys($columns)) as $field) {
            $errors[$field][] = 'La columna no pertenece a la plantilla oficial.';
        }
        $result = [];
        foreach ($columns as $field => $column) {
            $value = $values[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $value = null;
            } elseif (! is_string($value) && ! is_int($value)) {
                $errors[$field][] = 'La celda debe ser texto o un número exacto; no admite tipos compuestos ni valores booleanos.';
                $value = null;
            } elseif (is_int($value)) {
                $value = (string) $value;
            }
            if ($column['mode'] !== 'input') {
                if ($value !== null) {
                    $errors[$field][] = $column['mode'] === 'evidence'
                        ? 'Las evidencias se cargan después mediante su módulo; esta celda debe estar vacía.'
                        : 'Este valor se obtiene en el servidor; esta celda debe estar vacía.';
                }
                continue;
            }
            // Las observaciones conservan sus espacios; los tokens no admiten equivalencias silenciosas.
            if (in_array($field, ['is_protected', 'is_heritage'], true) || str_starts_with($field, 'condition_')) {
                if ($value !== null && ! in_array($value, ['0', '1'], true)) {
                    $errors[$field][] = 'Usa exclusivamente 0 o 1; una celda vacía no selecciona esta opción.';
                    $value = null;
                }
                $value = $value === '1';
            }
            if ($field === 'captured_at' && $value !== null
                && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
                $errors[$field][] = 'Usa fecha y hora ISO 8601 con zona explícita, por ejemplo 2026-09-28T10:30:00-05:00.';
            }
            $result[$field] = $value;
        }

        return $result;
    }

    private function reference(string $field, ?string $code, bool $lock, array &$errors): ?string
    {
        if ($code === null) {
            return null;
        }
        $id = $this->catalogs->resolve($field, $code, $lock);
        if ($id === null) {
            $errors[$field][] = 'El código no existe o no está disponible en su catálogo. Usa el código exacto de la hoja Catálogos.';
        }

        return $id;
    }

    private function conditions(array $values, array &$inspection, array &$errors, bool $lock): void
    {
        foreach ($values as $field => $selected) {
            if (! str_starts_with($field, 'condition_') || $selected !== true) {
                continue;
            }
            $id = $this->catalogs->resolve('condition', substr($field, strlen('condition_')), $lock);
            $type = $id === null ? null : $this->catalogs->conditionType($id);
            if (! in_array($type, ['physical_mechanical', 'phytosanitary'], true)) {
                $errors[$field][] = 'La condición o su categoría no está disponible para una nueva inspección.';
                continue;
            }
            $target = $type === 'physical_mechanical' ? 'physical_condition_ids' : 'phytosanitary_condition_ids';
            $inspection[$target][] = $id;
        }
    }

    private function fauna(?string $codes, bool $lock, array &$errors): array
    {
        if ($codes === null) {
            return [];
        }
        $items = explode('|', $codes);
        if (count($items) > 100 || count(array_unique($items)) !== count($items) || in_array('', $items, true)) {
            $errors['fauna_category_codes'][] = 'Indica como máximo 100 códigos distintos separados por |, sin segmentos vacíos.';
            return [];
        }
        $ids = [];
        foreach ($items as $code) {
            $id = $this->reference('fauna_category_codes', $code, $lock, $errors);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function capture(callable $operation, array &$errors): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $field = array_search($field, self::REFERENCES, true) ?: $field;
                $errors[$field] = array_merge($errors[$field] ?? [], $messages);
            }
        }
    }

    private function uniqueErrors(array $errors): array
    {
        return array_map(fn (array $messages): array => array_values(array_unique($messages)), $errors);
    }
}
