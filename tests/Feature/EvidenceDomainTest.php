<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StaleEvidenceException;
use App\Models\Evidence;
use App\Models\EvidenceRevision;
use App\Models\User;
use App\Services\EvidenceAuditService;
use App\Services\EvidenceReadService;
use App\Services\EvidenceRecoveryService;
use App\Services\EvidenceService;
use App\Services\EvidenceStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\EvidenceTestCase;

class EvidenceDomainTest extends EvidenceTestCase
{
    public function test_server_metadata_and_original_checksum_are_persisted(): void
    {
        $file = UploadedFile::fake()->image('individuo.jpg', 73, 51);
        $evidence = $this->attachEvidence(['file' => $file]);
        $this->assertSame('image/jpeg', $evidence->mime_type);
        $this->assertSame('jpg', $evidence->extension);
        $this->assertSame($file->getSize(), $evidence->size_bytes);
        $this->assertSame('individuo.jpg', $evidence->original_name);
        $this->assertSame(73, $evidence->width);
        $this->assertSame(51, $evidence->height);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $evidence->checksum_sha256);
        $this->assertSame($evidence->checksum_sha256, hash('sha256', Storage::disk('evidences')->get($evidence->path)));
        $this->assertNull($evidence->captured_at);
        $this->assertFalse($evidence->metadata['exif_extracted']);
    }

    #[DataProvider('emptyCaptureDates')]
    public function test_direct_service_normalizes_empty_capture_date_on_upload_and_update(string $empty): void
    {
        $evidence = $this->attachEvidence(['captured_at' => $empty]);
        $this->assertNull($evidence->fresh()->captured_at);
        $dated = app(EvidenceService::class)->update($this->admin, $evidence->id, [
            'lock_version' => 1, 'captured_at' => '2026-09-01T15:45',
        ]);
        $this->assertSame('2026-09-01 15:45:00', $dated->captured_at->utc()->format('Y-m-d H:i:s'));
        $cleared = app(EvidenceService::class)->update($this->admin, $evidence->id, [
            'lock_version' => 2, 'captured_at' => $empty,
        ]);
        $this->assertNull($cleared->fresh()->captured_at);
        $this->assertSame(3, $cleared->lock_version);
        $revision = EvidenceRevision::query()->where('evidence_id', $evidence->id)->where('version', 3)->sole();
        $this->assertNotNull($revision->old_values['captured_at']);
        $this->assertNull($revision->new_values['captured_at']);
        $this->assertSame($evidence->checksum_sha256, hash('sha256', Storage::disk('evidences')->get($evidence->path)));
    }

    public static function emptyCaptureDates(): array
    {
        return ['empty' => [''], 'whitespace' => ['   ']];
    }

    public function test_direct_read_service_normalizes_empty_optional_filters(): void
    {
        $evidence = $this->attachEvidence();
        $reader = app(EvidenceReadService::class);
        $fromOnly = $reader->index($this->admin, $this->tree->id, null, [
            'date_from' => $evidence->created_at->utc()->format('Y-m-d'), 'date_to' => '',
        ]);
        $this->assertSame(1, $fromOnly['gallery']['total']);
        $this->assertNull($fromOnly['filters']['date_to']);
        $unfiltered = $reader->index($this->admin, $this->tree->id, null, [
            'date_from' => '   ', 'date_to' => '   ', 'evidence_type_id' => '   ', 'trashed' => '   ',
        ]);
        $this->assertSame(1, $unfiltered['gallery']['total']);
        $this->assertSame(['evidence_type_id' => null, 'trashed' => null, 'date_from' => null, 'date_to' => null], $unfiltered['filters']);
    }

    public function test_multiple_types_are_independent_and_duplicate_bytes_are_allowed(): void
    {
        $input = $this->evidenceInput();
        $input['items'][] = array_replace($input['items'][0], ['evidence_type_id' => $this->evidenceType('EVD_STEM')->id]);
        $records = app(EvidenceService::class)->upload($this->admin, $this->tree->id, null, $input);
        $this->assertCount(2, $records);
        $this->assertNotSame($records[0]->evidence_type_id, $records[1]->evidence_type_id);
        $this->assertSame($records[0]->checksum_sha256, $records[1]->checksum_sha256);
        $this->assertNotSame($records[0]->path, $records[1]->path);
    }

    public function test_fauna_and_whole_tree_photos_belong_to_inspection_without_tree_duplication(): void
    {
        $inspection = $this->capture();
        $fauna = $this->attachEvidence(['evidence_type_id' => $this->evidenceType('EVD_FAUNA')->id], inspectionId: $inspection->id);
        $whole = $this->attachEvidence(inspectionId: $inspection->id);
        $this->assertNull($fauna->tree_id);
        $this->assertSame($inspection->id, $fauna->tree_inspection_id);
        $this->assertSame($inspection->id, $whole->tree_inspection_id);
        $this->assertSame(0, $this->tree->evidences()->count());
        $this->assertSame(2, $inspection->evidences()->count());
    }

    #[DataProvider('invalidOwners')]
    public function test_application_requires_exactly_one_owner(bool $tree, bool $inspection): void
    {
        $visit = $this->capture();
        $this->expectException(ValidationException::class);
        app(EvidenceService::class)->upload($this->admin, $tree ? $this->tree->id : null, $inspection ? $visit->id : null, $this->evidenceInput());
    }

    public static function invalidOwners(): array
    {
        return ['both' => [true, true], 'none' => [false, false]];
    }

    public function test_type_change_and_metadata_preserve_original_uploader_with_audit(): void
    {
        $evidence = $this->attachEvidence();
        $colleague = User::factory()->create();
        $this->member($colleague, $this->organization);
        $this->activateInspectionTenant($colleague);
        $updated = app(EvidenceService::class)->update($colleague, $evidence->id, [
            'lock_version' => 1, 'evidence_type_id' => $this->evidenceType('EVD_CROWN')->id,
            'caption' => 'Copa corregida', 'captured_at' => '2026-09-01T15:45',
        ]);
        $this->assertSame((int) $this->admin->id, $updated->uploaded_by);
        $this->assertSame((int) $colleague->id, $updated->updated_by);
        $this->assertSame('2026-09-01 15:45:00', $updated->captured_at->utc()->format('Y-m-d H:i:s'));
        $revision = EvidenceRevision::query()->where('evidence_id', $evidence->id)->where('version', 2)->sole();
        $this->assertSame($evidence->evidence_type_id, $revision->old_values['evidence_type_id']);
        $this->assertSame($updated->evidence_type_id, $revision->new_values['evidence_type_id']);
        foreach (['path', 'disk', 'metadata', 'checksum_sha256'] as $private) {
            $this->assertArrayNotHasKey($private, $revision->new_values);
        }
    }

    public function test_inactive_historical_type_can_remain_but_cannot_be_newly_selected(): void
    {
        $evidence = $this->attachEvidence();
        $evidence->evidenceType->forceFill(['active' => false])->save();
        $updated = app(EvidenceService::class)->update($this->admin, $evidence->id, ['lock_version' => 1, 'caption' => 'Conservada']);
        $this->assertSame($evidence->evidence_type_id, $updated->evidence_type_id);
        $this->expectException(ValidationException::class);
        $this->attachEvidence();
    }

    public function test_stale_version_cannot_overwrite_first_edit(): void
    {
        $evidence = $this->attachEvidence();
        app(EvidenceService::class)->update($this->admin, $evidence->id, ['lock_version' => 1, 'caption' => 'Primera']);
        try {
            app(EvidenceService::class)->update($this->admin, $evidence->id, ['lock_version' => 1, 'caption' => 'Obsoleta']);
            $this->fail('Debe detectar conflicto.');
        } catch (StaleEvidenceException $exception) {
            $this->assertSame('La evidencia fue modificada por otro usuario.', $exception->getMessage());
        }
        $this->assertSame('Primera', $evidence->fresh()->caption);
        $this->assertSame(2, $evidence->fresh()->lock_version);
    }

    #[DataProvider('invalidVersions')]
    public function test_lock_version_is_not_silently_coerced(mixed $value): void
    {
        $evidence = $this->attachEvidence();
        $this->expectException(ValidationException::class);
        app(EvidenceService::class)->update($this->admin, $evidence->id, ['lock_version' => $value]);
    }

    public static function invalidVersions(): array
    {
        return [[true], [1.0], ['1.0'], ['1e0'], ['01'], [' 1'], [null], [0], [2147483647]];
    }

    public function test_soft_delete_preserves_file_and_restore_reuses_identity(): void
    {
        $evidence = $this->attachEvidence();
        $voided = app(EvidenceService::class)->void($this->admin, $evidence->id, ['lock_version' => 1, 'deletion_notes' => 'Carga equivocada']);
        $this->assertTrue($voided->trashed());
        $this->assertSame((int) $this->admin->id, $voided->deleted_by);
        Storage::disk('evidences')->assertExists($evidence->path);
        $this->assertSame(0, app(EvidenceReadService::class)->gallery($this->admin, $this->tree)['total']);
        $restored = app(EvidenceService::class)->restore($this->admin, $evidence->id, ['lock_version' => 2]);
        $this->assertFalse($restored->trashed());
        $this->assertSame($evidence->id, $restored->id);
        $this->assertSame($evidence->path, $restored->path);
        $this->assertSame($evidence->uploaded_by, $restored->uploaded_by);
        $this->assertSame(1, app(EvidenceReadService::class)->gallery($this->admin, $this->tree)['total']);
    }

    public function test_missing_file_cannot_be_restored(): void
    {
        $evidence = $this->attachEvidence();
        app(EvidenceService::class)->void($this->admin, $evidence->id, ['lock_version' => 1]);
        Storage::disk('evidences')->delete($evidence->path);
        try {
            app(EvidenceService::class)->restore($this->admin, $evidence->id, ['lock_version' => 2]);
            $this->fail('No puede restaurarse sin archivo.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no está disponible', $exception->errors()['file'][0]);
        }
        $this->assertTrue(Evidence::withTrashed()->findOrFail($evidence->id)->trashed());
        $this->assertSame(2, EvidenceRevision::query()->where('evidence_id', $evidence->id)->count());
    }

    public function test_bad_last_image_aborts_entire_batch_before_writing(): void
    {
        $input = $this->evidenceInput();
        $input['items'][] = array_replace($input['items'][0], ['file' => UploadedFile::fake()->createWithContent('falsa.jpg', 'contenido falso')]);
        try {
            app(EvidenceService::class)->upload($this->admin, $this->tree->id, null, $input);
            $this->fail('El lote inválido debe rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.1.file', $exception->errors());
        }
        $this->assertSame(0, Evidence::query()->count());
        $this->assertSame([], Storage::disk('evidences')->allFiles());
    }

    public function test_failed_audit_rolls_back_database_and_compensates_image(): void
    {
        $this->mock(EvidenceAuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('fallo simulado'));
        try {
            $this->attachEvidence();
            $this->fail('Debe fallar atómicamente.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame(0, Evidence::query()->count());
        $this->assertSame([], Storage::disk('evidences')->allFiles());
    }

    public function test_second_file_storage_failure_compensates_first_file(): void
    {
        $storage = app(EvidenceStorageService::class);
        $this->mock(EvidenceStorageService::class, function ($mock) use ($storage): void {
            $mock->shouldReceive('store')->once()->ordered()->andReturnUsing(fn (...$args) => $storage->store(...$args));
            $mock->shouldReceive('store')->once()->ordered()->andThrow(new RuntimeException('fallo filesystem'));
            $mock->shouldReceive('findPending')->andReturnUsing(fn (...$args) => $storage->findPending(...$args));
            $mock->shouldReceive('deleteWritten')->andReturnUsing(fn (...$args) => $storage->deleteWritten(...$args));
        });
        $input = $this->evidenceInput();
        $input['items'][] = $input['items'][0];
        try {
            app(EvidenceService::class)->upload($this->admin, $this->tree->id, null, $input);
            $this->fail('Debe fallar el lote.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame(0, Evidence::query()->count());
        $this->assertSame([], Storage::disk('evidences')->allFiles());
    }

    public function test_reconciliation_keeps_committed_and_voided_files(): void
    {
        $evidence = $this->attachEvidence();
        app(EvidenceService::class)->void($this->admin, $evidence->id, ['lock_version' => 1]);
        // Simula un commit confirmado cuya retirada del journal fue interrumpida.
        Storage::disk('evidences')->put('.pending/'.$evidence->id.'.json', json_encode([
            'disk' => $evidence->disk, 'path' => $evidence->path, 'operation_id' => $evidence->id,
        ], JSON_THROW_ON_ERROR));
        $this->assertTrue(app(EvidenceRecoveryService::class)->recover($evidence->id));
        Storage::disk('evidences')->assertExists($evidence->path);
        $this->assertNull(app(EvidenceStorageService::class)->findPending($evidence->id));
        $this->assertFalse(app(EvidenceRecoveryService::class)->recover($evidence->id));
    }

    public function test_reconciliation_removes_only_unreferenced_pending_upload(): void
    {
        $id = (string) Str::uuid();
        $file = UploadedFile::fake()->image('pendiente.png');
        $technical = app(\App\Services\ImageMetadataService::class)->inspect($file);
        $reference = app(EvidenceStorageService::class)->store($file, $this->organization->id, $technical, $id);
        $this->assertTrue(app(EvidenceRecoveryService::class)->recover($id));
        Storage::disk('evidences')->assertMissing($reference['path']);
        $this->assertSame(0, Evidence::query()->count());
    }

    public function test_configured_batch_limit_is_enforced_independently(): void
    {
        config(['evidence.max_batch_size_mb' => 0]);
        $this->expectException(ValidationException::class);
        $this->attachEvidence();
    }

    #[DataProvider('immutableInputs')]
    public function test_service_rejects_client_provenance(string $field, mixed $value): void
    {
        $evidence = $this->attachEvidence();
        $this->expectException(ValidationException::class);
        app(EvidenceService::class)->update($this->admin, $evidence->id, ['lock_version' => 1, $field => $value]);
    }

    public static function immutableInputs(): array
    {
        return [['uploaded_by', 123], ['organization_id', 'untrusted'], ['path', '../outside.jpg'], ['disk', 'public'],
            ['mime_type', 'text/html'], ['metadata', ['gps' => 'false']], ['tree_id', 'untrusted'], ['is_public', true]];
    }
}
