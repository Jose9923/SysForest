<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ExportFileRepository;

class ExportCleanupService
{
    public function __construct(private ExportFileRepository $files)
    {
    }

    public function run(): int
    {
        return $this->files->cleanup(now()->timestamp - max(3600, (int) config('reports.temporary_ttl_seconds', 3600)));
    }
}
