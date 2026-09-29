<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\SecurityEvent;
use App\Models\Tree;
use App\Models\TreeInspection;
use App\Models\User;
use App\Repositories\ImportRowRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\ImportService;
use App\Services\ImportRowWriteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\ImportTestCase;

class ImportWorkflowTest extends ImportTestCase
{
    public function test_preview_preserves_source_and_calculations_without_creating_individuals(): void
    {
        $before = Tree::withTrashed()->count();
        $file = $this->workbook([$this->importInput()]);
        $batch = app(ImportService::class)->upload($this->admin, $file, $this->inventory->id);
        $this->assertSame(ImportBatchStatus::Ready, $batch->status);
        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(0, $batch->imported_rows);
        $this->assertSame(hash_file('sha256', $file->getPathname()), $batch->file_sha256);
        $this->assertSame($before, Tree::withTrashed()->count());
        $row = ImportRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame(2, $row->row_number);
        $this->assertSame('000015', $row->raw_values['external_number']);
        $this->assertArrayHasKey('dap_m', $row->normalized_values['calculations']);
        $this->assertSame('valid', $row->status);
        $this->assertNull($row->tree_id);
        $this->assertDatabaseHas('security_events', ['event' => 'import.preview_created', 'organization_id' => $this->organization->id]);
        $this->assertTrue(app(ImportService::class)->show($this->admin, $batch->id)['canConfirm']);
    }

    public function test_complete_batch_preserves_requested_code_and_is_idempotent_after_confirmation(): void
    {
        $before = Tree::withTrashed()->count();
        $code = $this->organization->tree_code_prefix.'-000075';
        $batch = $this->upload([$this->importInput(), $this->importInput(['code' => $code, 'external_number' => '000076'])]);
        $service = app(ImportService::class);
        $completed = $service->confirm($this->admin, $batch->id, 1);
        $this->assertSame(ImportBatchStatus::Completed, $completed->status);
        $this->assertSame(2, $completed->imported_rows);
        $this->assertSame($before + 2, Tree::withTrashed()->count());
        $rows = ImportRow::query()->where('import_batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertSame($this->organization->tree_code_prefix.'-000076', $rows[0]->tree_code);
        $this->assertSame($code, $rows[1]->tree_code);
        foreach ($rows as $row) {
            $tree = Tree::query()->findOrFail($row->tree_id);
            $inspection = TreeInspection::query()->findOrFail($row->inspection_id);
            $this->assertSame($tree->id, $inspection->tree_id);
            $this->assertFalse($tree->is_public);
            $this->assertFalse($inspection->is_public);
            $this->assertSame((int) $this->admin->id, (int) $tree->created_by);
            $this->assertNotNull($inspection->inventory_tree_id);
            $this->assertSame($row->normalized_values['calculations']['dap_m'], $inspection->dap_m);
        }
        $repeat = $service->confirm($this->admin, $batch->id, 1);
        $this->assertSame($completed->id, $repeat->id);
        $this->assertSame(2, $repeat->lock_version);
        $this->assertSame($before + 2, Tree::withTrashed()->count());
        $this->assertSame(1, SecurityEvent::query()->where('event', 'import.completed')->where('metadata->resource_id', $batch->id)->count());
    }

    public function test_one_invalid_row_blocks_every_row_and_displays_specific_errors(): void
    {
        $before = Tree::withTrashed()->count();
        $batch = $this->upload([$this->importInput(), $this->importInput(['cap_cm' => '-1', 'coordinate_raw' => '91, -73'])]);
        $this->assertSame(ImportBatchStatus::Invalid, $batch->status);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(1, $batch->invalid_rows);
        $row = ImportRow::query()->where('import_batch_id', $batch->id)->where('row_number', 3)->sole();
        $this->assertArrayHasKey('cap_cm', $row->errors);
        $this->assertArrayHasKey('coordinate_raw', $row->errors);
        try {
            app(ImportService::class)->confirm($this->admin, $batch->id, 1);
            $this->fail('Un lote inválido no puede confirmarse.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($before, Tree::withTrashed()->count());
    }

    public function test_duplicate_codes_are_reported_on_both_rows(): void
    {
        $input = $this->importInput(['code' => $this->organization->tree_code_prefix.'-000050']);
        $batch = $this->upload([$input, $input]);
        $this->assertSame(2, $batch->invalid_rows);
        foreach (ImportRow::query()->where('import_batch_id', $batch->id)->get() as $row) {
            $this->assertArrayHasKey('code', $row->errors);
        }
    }

    public function test_identical_file_cannot_be_imported_again_even_through_another_preview(): void
    {
        $file = $this->workbook([$this->importInput()]);
        $service = app(ImportService::class);
        $first = $service->upload($this->admin, $file, $this->inventory->id);
        $second = $service->upload($this->admin, $file, $this->inventory->id);
        $service->confirm($this->admin, $first->id, 1);
        $this->assertSame(ImportBatchStatus::Cancelled, $service->confirm($this->admin, $second->id, 1)->status);
        $this->expectException(ValidationException::class);
        $service->upload($this->admin, $file, $this->inventory->id);
    }

    public function test_expired_preview_is_cancelled_without_importing_any_row(): void
    {
        $batch = $this->upload([$this->importInput()]);
        $before = Tree::withTrashed()->count();
        $this->travel(25)->hours();
        $this->assertFalse(app(ImportService::class)->show($this->admin, $batch->id)['canConfirm']);
        $result = app(ImportService::class)->confirm($this->admin, $batch->id, 1);
        $this->assertSame(ImportBatchStatus::Cancelled, $result->status);
        $this->assertStringContainsString('caducó', $result->notice);
        $this->assertSame($before, Tree::withTrashed()->count());
    }

    public function test_changed_normalized_preview_requires_a_second_explicit_confirmation(): void
    {
        $batch = $this->upload([$this->importInput()]);
        $row = ImportRow::query()->where('import_batch_id', $batch->id)->sole();
        $values = $row->normalized_values;
        $values['calculations']['dap_m'] = '999';
        app(ImportRowRepository::class)->update($row, ['normalized_values' => $values]);
        $before = Tree::withTrashed()->count();
        $service = app(ImportService::class);
        $review = $service->confirm($this->admin, $batch->id, 1);
        $this->assertSame(ImportBatchStatus::Ready, $review->status);
        $this->assertSame(2, $review->lock_version);
        $this->assertSame($before, Tree::withTrashed()->count());
        try {
            $service->confirm($this->admin, $batch->id, 1);
            $this->fail('Debe rechazarse la confirmación de una versión antigua.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(ImportBatchStatus::Completed, $service->confirm($this->admin, $batch->id, 2)->status);
    }

    public function test_inventory_closed_after_preview_becomes_a_row_error(): void
    {
        $batch = $this->upload([$this->importInput()]);
        DB::table('inventories')->where('id', $this->inventory->id)->update(['status' => 'closed']);
        $result = app(ImportService::class)->confirm($this->admin, $batch->id, 1);
        $this->assertSame(ImportBatchStatus::Invalid, $result->status);
        $this->assertArrayHasKey('inventory_id', ImportRow::query()->where('import_batch_id', $batch->id)->sole()->errors);
    }

    public function test_a_late_row_failure_rolls_back_previous_rows_and_sequence_reservations(): void
    {
        $batch = $this->upload([$this->importInput(), $this->importInput(['external_number' => '2'])]);
        $before = Tree::withTrashed()->count();
        $original = app(ImportRowWriteService::class);
        $called = 0;
        $this->mock(ImportRowWriteService::class)->shouldReceive('reserveCodes')->once()->andReturnUsing($original->reserveCodes(...));
        app(ImportRowWriteService::class)->shouldReceive('create')->twice()->andReturnUsing(function (...$args) use ($original, &$called) {
            if (++$called === 2) {
                throw new RuntimeException('Fallo simulado al escribir la segunda fila.');
            }
            return $original->create(...$args);
        });
        try {
            app(ImportService::class)->confirm($this->admin, $batch->id, 1);
            $this->fail('La escritura debe fallar.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('simulado', $exception->getMessage());
        }
        $this->assertSame($before, Tree::withTrashed()->count());
        $this->assertSame(ImportBatchStatus::Ready, $batch->fresh()->status);
        $this->assertSame(0, ImportRow::query()->where('import_batch_id', $batch->id)->whereNotNull('tree_id')->count());
    }

    public function test_final_audit_failure_rolls_back_the_entire_batch(): void
    {
        $batch = $this->upload([$this->importInput()]);
        $before = Tree::withTrashed()->count();
        $audit = app(SecurityEventRepository::class);
        $this->partialMock(SecurityEventRepository::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($audit) {
            if ($args[0] === 'import.completed') {
                throw new RuntimeException('Auditoría del lote no disponible.');
            }
            return $audit->record(...$args);
        });
        try {
            app(ImportService::class)->confirm($this->admin, $batch->id, 1);
            $this->fail('La auditoría es obligatoria.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Auditoría del lote no disponible.', $exception->getMessage());
        }
        $this->assertSame($before, Tree::withTrashed()->count());
        $this->assertSame(ImportBatchStatus::Ready, $batch->fresh()->status);
    }

    public function test_collaborative_confirmation_preserves_upload_author_and_records_the_confirmer(): void
    {
        $batch = $this->upload([$this->importInput()]);
        $colleague = User::factory()->create();
        $this->member($colleague, $this->organization);
        $this->activateInspectionTenant($colleague);
        $result = app(ImportService::class)->confirm($colleague, $batch->id, 1);
        $this->assertSame((int) $this->admin->id, (int) $result->created_by);
        $this->assertSame((int) $colleague->id, (int) $result->confirmed_by);
        $row = ImportRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame((int) $colleague->id, (int) Tree::query()->findOrFail($row->tree_id)->created_by);
    }

    public function test_cancel_keeps_rows_and_prevents_future_confirmation(): void
    {
        $batch = $this->upload([$this->importInput()]);
        $service = app(ImportService::class);
        $this->assertSame(ImportBatchStatus::Cancelled, $service->cancel($this->admin, $batch->id, 1)->status);
        $this->assertSame(1, ImportRow::query()->where('import_batch_id', $batch->id)->count());
        $this->assertSame(ImportBatchStatus::Cancelled, $service->cancel($this->admin, $batch->id, 1)->status);
        $this->expectException(HttpException::class);
        $service->confirm($this->admin, $batch->id, 2);
    }

    private function upload(array $rows): ImportBatch
    {
        return app(ImportService::class)->upload($this->admin, $this->workbook($rows), $this->inventory->id);
    }
}
