<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LocalAccessService;
use Illuminate\Console\Command;

class LocalAccessCommand extends Command
{
    protected $signature = 'sysforest:local-access {email} {--organization=INEM-VVC} {--create-user} {--join}';
    protected $description = 'Emite un enlace técnico local de un solo uso para revisar la Fase 3.';

    public function handle(LocalAccessService $service): int
    {
        $url = $service->issue(
            (string) $this->argument('email'),
            (string) $this->option('organization'),
            (bool) $this->option('create-user'),
            (bool) $this->option('join')
        );

        $this->info('Enlace de un solo uso, válido 10 minutos. La sesión técnica dura 30 minutos.');
        $this->line($url);

        return self::SUCCESS;
    }
}
