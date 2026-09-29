<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ImportRow;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Reports\InventoryExportSchema;
use App\Services\DendrometryCalculationService;
use App\Services\ImportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\ImportTestCase;

/** Mide la capacidad del lote completo únicamente en sysforest_testing; el tiempo se informa, no se impone como aserción. */
class ImportPerformanceTest extends ImportTestCase
{
    public function test_configured_limit_with_all_conditions_is_complete_atomic_and_private(): void
    {
        $rowCount = (int) config('imports.max_rows');
        $this->assertGreaterThan(0, $rowCount);
        $flags = [];
        foreach (array_keys(InventoryExportSchema::ORIGINAL_CONDITIONS) as $code) {
            $flags['condition_'.$code] = '1';
        }
        $input = $this->importInput($flags);
        $rows = [];
        for ($index = 0; $index < $rowCount; $index++) {
            $rows[] = array_replace($input, ['external_number' => 'PERF-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT)]);
        }
        // Crear el libro se mide aparte: la operación HTTP recibe un archivo ya preparado.
        $fileStarted = hrtime(true);
        $file = $this->workbook($rows);
        $fileSeconds = (hrtime(true) - $fileStarted) / 1_000_000_000;
        $beforeTrees = Tree::query()->count();
        $beforeInspections = TreeInspection::query()->count();
        $beforeConditions = DB::table('inspection_conditions')->count();
        $queryCount = 0;
        $collecting = true;
        DB::listen(static function (QueryExecuted $event) use (&$queryCount, &$collecting): void {
            if ($collecting) {
                $queryCount++;
            }
        });
        $metrics = ['rows' => $rowCount, 'conditionsPerRow' => count($flags),
            'fileSeconds' => $fileSeconds, 'uploadSeconds' => null, 'confirmationSeconds' => null];
        $service = app(ImportService::class);
        try {
            $started = hrtime(true);
            $batch = $service->upload($this->admin, $file, $this->inventory->id);
            $metrics['uploadSeconds'] = (hrtime(true) - $started) / 1_000_000_000;
            $this->assertSame(ImportBatchStatus::Ready, $batch->status);
            $this->assertSame($rowCount, $batch->valid_rows);
            $this->assertSame(0, $batch->invalid_rows);
            $this->assertSame($beforeTrees, Tree::query()->count());
            $this->assertSame($beforeInspections, TreeInspection::query()->count());

            $started = hrtime(true);
            $completed = $service->confirm($this->admin, $batch->id, $batch->lock_version);
            $metrics['confirmationSeconds'] = (hrtime(true) - $started) / 1_000_000_000;
        } finally {
            $collecting = false;
            $metrics['totalQueries'] = $queryCount;
            $metrics['peakMemoryMb'] = memory_get_peak_usage(true) / 1024 / 1024;
            file_put_contents(
                '/tmp/sysforest-phase13-import-performance.json',
                json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL
            );
        }

        $this->assertSame(ImportBatchStatus::Completed, $completed->status);
        $this->assertSame($rowCount, $completed->total_rows);
        $this->assertSame($rowCount, $completed->imported_rows);
        $this->assertSame($beforeTrees + $rowCount, Tree::query()->count());
        $this->assertSame($beforeInspections + $rowCount, TreeInspection::query()->count());
        $this->assertSame($beforeConditions + $rowCount * count($flags), DB::table('inspection_conditions')->count());

        $saved = ImportRow::query()->where('organization_id', $this->organization->id)
            ->where('import_batch_id', $completed->id)->get();
        $this->assertCount($rowCount, $saved);
        $this->assertCount($rowCount, $saved->pluck('tree_id')->unique());
        $this->assertCount($rowCount, $saved->pluck('tree_code')->unique());
        $this->assertCount($rowCount, $saved->pluck('inspection_id')->unique());
        $this->assertSame(['imported'], $saved->pluck('status')->unique()->values()->all());
        $this->assertFalse(Tree::query()->whereIn('id', $saved->pluck('tree_id'))->where('is_public', true)->exists());
        $this->assertFalse(TreeInspection::query()->whereIn('id', $saved->pluck('inspection_id'))->where('is_public', true)->exists());

        $expected = app(DendrometryCalculationService::class)->calculate($input);
        $inspections = TreeInspection::query()->whereIn('id', $saved->pluck('inspection_id'))->get();
        foreach ($inspections as $inspection) {
            $this->assertSame('154.000000', $inspection->cap_cm);
            $this->assertSame($expected['dap_m'], $inspection->dap_m);
            $this->assertSame($expected['basal_area_m2'], $inspection->basal_area_m2);
            $this->assertSame($expected['total_volume_m3'], $inspection->total_volume_m3);
            $this->assertSame($expected['classification_rule_version_id'], $inspection->classification_rule_version_id);
        }
    }
}
