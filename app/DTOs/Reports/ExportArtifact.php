<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

/** Archivo completo y efímero; la ruta solo se comunica al transporte interno. */
final readonly class ExportArtifact
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mime,
        public int $rows,
    ) {
    }
}
