<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Evidence;
use App\Services\EvidenceStorageJournal;
use App\Services\EvidenceStorageService;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EvidenceStorageServiceTest extends TestCase
{
    private EvidenceStorageService $storage;
    private Filesystem $disk;
    private string $organization;
    private string $diskName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskName = 'evidence_unit_'.str_replace('-', '', (string) Str::uuid());
        config(['evidence.disk' => $this->diskName]);
        $this->disk = Storage::fake($this->diskName);
        $this->storage = app(EvidenceStorageService::class);
        $this->organization = (string) Str::uuid();
    }

    public function test_store_creates_private_original_and_minimal_durable_journal(): void
    {
        $file = UploadedFile::fake()->createWithContent('../../foto.jpg', 'original de prueba');
        $operation = (string) Str::uuid();
        $reference = $this->storage->store($file, $this->organization, ['extension' => 'jpg', 'size_bytes' => $file->getSize()], $operation);

        $this->assertSame('organizations/'.$this->organization.'/'.$operation.'.jpg', $reference['path']);
        $this->assertSame($this->diskName, $reference['disk']);
        $this->assertSame($operation, $reference['operation_id']);
        $this->assertSame('original de prueba', $this->disk->get($reference['path']));
        $this->assertSame('private', $this->disk->getVisibility($reference['path']));
        $this->assertSame($reference, $this->storage->findPending($operation));
        $this->assertSame([$reference], $this->storage->pendingOperations());
        $this->assertSame(['disk', 'path', 'operation_id'], array_keys($this->storage->findPending($operation)));
    }

    public function test_acknowledgement_removes_only_journal_and_can_repeat(): void
    {
        $reference = $this->store();
        $this->storage->acknowledgeWritten([$reference]);
        $this->storage->acknowledgeWritten([$reference]);

        $this->assertTrue($this->disk->exists($reference['path']));
        $this->assertNull($this->storage->findPending($reference['operation_id']));
        $this->assertSame([], $this->storage->pendingOperations());
    }

    public function test_compensation_removes_uncommitted_file_and_journal(): void
    {
        $reference = $this->store();
        $this->storage->deleteWritten([$reference]);
        $this->storage->deleteWritten([$reference]);

        $this->assertFalse($this->disk->exists($reference['path']));
        $this->assertNull($this->storage->findPending($reference['operation_id']));
    }

    public function test_compensation_refuses_an_acknowledged_file_without_journal(): void
    {
        $reference = $this->store();
        $this->storage->acknowledgeWritten([$reference]);
        try {
            $this->storage->deleteWritten([$reference]);
            $this->fail('No debe borrar una fotografía sin intención pendiente.');
        } catch (RuntimeException) {
            $this->assertTrue($this->disk->exists($reference['path']));
        }
    }

    public function test_operation_id_cannot_overwrite_an_existing_journal_or_file(): void
    {
        $reference = $this->store();
        $file = UploadedFile::fake()->createWithContent('foto.png', 'reemplazo');
        try {
            $this->storage->store($file, $this->organization, ['extension' => 'png', 'size_bytes' => $file->getSize()], $reference['operation_id']);
            $this->fail('Se reutilizó una operación.');
        } catch (RuntimeException) {
            $this->assertSame('contenido', $this->disk->get($reference['path']));
            $this->assertSame($reference, $this->storage->findPending($reference['operation_id']));
        }
    }

    public function test_missing_pending_operation_returns_null(): void
    {
        $this->assertNull($this->storage->findPending((string) Str::uuid()));
    }

    public function test_path_traversal_reference_never_deletes_another_object(): void
    {
        $this->disk->put('protected.txt', 'conservar');
        try {
            $this->storage->deleteWritten([['disk' => $this->diskName, 'path' => '../protected.txt', 'operation_id' => (string) Str::uuid()]]);
            $this->fail('Se aceptó una ruta ajena.');
        } catch (RuntimeException) {
            $this->assertSame('conservar', $this->disk->get('protected.txt'));
        }
    }

    public function test_tampered_manifest_with_another_disk_is_rejected(): void
    {
        $reference = $this->store();
        $this->disk->put('.pending/'.$reference['operation_id'].'.json', json_encode(array_replace($reference, ['disk' => 'public'])));
        $this->expectException(RuntimeException::class);
        $this->storage->findPending($reference['operation_id']);
    }

    public function test_oversized_manifest_is_rejected_without_interpreting_its_content(): void
    {
        $operation = (string) Str::uuid();
        $this->disk->put('.pending/'.$operation.'.json', str_repeat('x', 2049));
        $this->expectException(RuntimeException::class);
        $this->storage->findPending($operation);
    }

    public function test_manifest_operation_must_match_filename_and_object_path(): void
    {
        $reference = $this->store();
        $other = (string) Str::uuid();
        $this->disk->put('.pending/'.$other.'.json', json_encode($reference));
        $this->expectException(RuntimeException::class);
        $this->storage->findPending($other);
    }

    public function test_journal_failure_prevents_any_original_write(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->once()->withArgs(fn ($path) => str_starts_with($path, '.pending/'))->andReturn(false);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('disk')->with($this->diskName)->andReturn($disk);
        $service = new EvidenceStorageService(new EvidenceStorageJournal($factory));
        $file = UploadedFile::fake()->createWithContent('foto.png', 'contenido');
        $this->expectException(RuntimeException::class);
        $service->store($file, $this->organization, ['extension' => 'png', 'size_bytes' => $file->getSize()], (string) Str::uuid());
    }

    public function test_partial_write_failure_compensates_its_own_file(): void
    {
        $service = $this->faultyService(false);
        $operation = (string) Str::uuid();
        $file = UploadedFile::fake()->createWithContent('foto.png', 'contenido');
        try {
            $service->store($file, $this->organization, ['extension' => 'png', 'size_bytes' => $file->getSize()], $operation);
            $this->fail('La escritura parcial se presentó como exitosa.');
        } catch (RuntimeException) {
            $this->assertSame([], $this->disk->allFiles());
        }
    }

    public function test_failed_partial_write_cleanup_leaves_intention_for_reconciliation(): void
    {
        $service = $this->faultyService(true);
        $operation = (string) Str::uuid();
        $file = UploadedFile::fake()->createWithContent('foto.png', 'contenido');
        try {
            $service->store($file, $this->organization, ['extension' => 'png', 'size_bytes' => $file->getSize()], $operation);
            $this->fail('La escritura parcial se presentó como exitosa.');
        } catch (RuntimeException) {
            $pending = $this->storage->findPending($operation);
            $this->assertNotNull($pending);
            $this->assertSame('parcial', $this->disk->get($pending['path']));
            $this->storage->deleteWritten([$pending]);
            $this->assertNull($this->storage->findPending($operation));
            $this->assertFalse($this->disk->exists($pending['path']));
        }
    }

    public function test_batch_compensation_continues_after_one_invalid_reference(): void
    {
        $reference = $this->store();
        try {
            $this->storage->deleteWritten([['path' => '../../ajeno'], $reference]);
            $this->fail('El error de una referencia debe informarse.');
        } catch (RuntimeException) {
            $this->assertFalse($this->disk->exists($reference['path']));
            $this->assertNull($this->storage->findPending($reference['operation_id']));
        }
    }

    public function test_incomplete_stored_size_causes_compensation(): void
    {
        $file = UploadedFile::fake()->createWithContent('foto.png', 'contenido');
        try {
            $this->storage->store($file, $this->organization, ['extension' => 'png', 'size_bytes' => 999], (string) Str::uuid());
            $this->fail('Se aceptó un tamaño distinto del inspeccionado.');
        } catch (RuntimeException) {
            $this->assertSame([], $this->disk->allFiles());
        }
    }

    public function test_read_returns_stream_and_never_requires_a_physical_local_path(): void
    {
        $reference = $this->store();
        $evidence = $this->model($reference);
        $this->assertTrue($this->storage->exists($evidence));
        $stream = $this->storage->read($evidence);
        $this->assertSame('contenido', stream_get_contents($stream));
        fclose($stream);
        $this->storage->acknowledgeWritten([$reference]);
        $this->assertTrue($this->storage->exists($evidence));
    }

    public function test_model_organization_must_match_the_internal_object_prefix(): void
    {
        $evidence = $this->model($this->store());
        $evidence->organization_id = (string) Str::uuid();
        $this->expectException(RuntimeException::class);
        $this->storage->exists($evidence);
    }

    public function test_missing_original_is_reported_without_returning_fake_stream(): void
    {
        $reference = $this->store();
        $this->disk->delete($reference['path']);
        $this->assertFalse($this->storage->exists($this->model($reference)));
        $this->expectException(RuntimeException::class);
        $this->storage->read($this->model($reference));
    }

    private function model(array $reference): Evidence
    {
        $model = new Evidence();
        $model->forceFill(['organization_id' => $this->organization, 'disk' => $reference['disk'], 'path' => $reference['path']]);

        return $model;
    }

    private function store(): array
    {
        $file = UploadedFile::fake()->createWithContent('foto.png', 'contenido');

        return $this->storage->store($file, $this->organization, ['extension' => 'png', 'size_bytes' => $file->getSize()], (string) Str::uuid());
    }

    private function faultyService(bool $cleanupFails): EvidenceStorageService
    {
        $disk = Mockery::mock(Filesystem::class);
        foreach (['exists', 'get', 'size', 'files', 'readStream'] as $method) {
            $disk->shouldReceive($method)->andReturnUsing(fn (...$args) => $this->disk->{$method}(...$args));
        }
        $disk->shouldReceive('put')->andReturnUsing(function (string $path, $contents, array $options) {
            if (str_starts_with($path, '.pending/')) {
                return $this->disk->put($path, $contents, $options);
            }
            $this->disk->put($path, 'parcial', $options);
            throw new RuntimeException('Fallo simulado después de escribir.');
        });
        $disk->shouldReceive('delete')->andReturnUsing(fn ($path) => $cleanupFails && ! str_starts_with($path, '.pending/')
            ? false : $this->disk->delete($path));
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('disk')->with($this->diskName)->andReturn($disk);

        return new EvidenceStorageService(new EvidenceStorageJournal($factory));
    }
}
