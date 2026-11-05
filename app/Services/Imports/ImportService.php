<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Imports\ImportBatch;
use App\Models\User;
use App\Repositories\Reports\ExportFileRepository;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Fachada de casos de uso; la lectura, validación, confirmación y plantilla tienen colaboradores propios. */
class ImportService
{
    public function __construct(
        private ImportReadService $reads,
        private ImportUploadService $uploads,
        private ImportConfirmationService $confirmation,
        private ImportAccessService $access,
        private ImportTemplateService $templates,
        private ExportFileRepository $files,
    ) {
    }

    public function index(User $actor): array
    {
        return $this->reads->index($actor);
    }

    public function form(User $actor): array
    {
        return $this->reads->form($actor);
    }

    public function show(User $actor, string $id): array
    {
        return $this->reads->show($actor, $id);
    }

    public function upload(User $actor, UploadedFile $file, string $inventoryId): ImportBatch
    {
        return $this->uploads->upload($actor, $file, $inventoryId);
    }

    public function confirm(User $actor, string $id, int $version): ImportBatch
    {
        return $this->confirmation->confirm($actor, $id, $version);
    }

    public function cancel(User $actor, string $id, int $version): ImportBatch
    {
        return $this->confirmation->cancel($actor, $id, $version);
    }

    public function downloadTemplate(User $actor): BinaryFileResponse
    {
        $this->access->authorize($actor);
        $path = $this->files->create();
        try {
            $this->templates->write($path);
            $this->access->authorize($actor);

            return response()->download($path, 'sysforest-plantilla-oficial-v2-es.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            $this->files->remove($path);
            throw $exception;
        }
    }
}
