<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ExportCleanupService;
use Illuminate\Console\Command;

class CleanupReportDownloads extends Command
{
    protected $signature = 'sysforest:cleanup-report-downloads';

    protected $description = 'Elimina archivos de reportes huérfanos con más de una hora de antigüedad.';

    public function handle(ExportCleanupService $cleanup): int
    {
        $this->info('Temporales de reportes eliminados: '.$cleanup->run());

        return self::SUCCESS;
    }
}
