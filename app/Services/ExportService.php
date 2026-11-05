<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ExportArtifact;
use App\DTOs\ReportSelectionDTO;
use App\Models\User;
use App\Reports\ExportFormat;
use App\Reports\Writers\CsvExportWriter;
use App\Reports\Writers\GeoJsonExportWriter;
use App\Reports\Writers\XlsxExportWriter;
use App\Repositories\ExportFileRepository;
use App\Repositories\ExportSourceRepository;
use App\Repositories\ReportSnapshotRepository;
use App\Repositories\SecurityEventRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Coordina autorización, instantánea, archivo privado y revalidación inmediatamente antes de su entrega. */
class ExportService
{
    public function __construct(
        private ReportFilterService $filters,
        private ReportAccessService $access,
        private ReportReadService $presentation,
        private ExportDatasetService $datasets,
        private ReportSnapshotRepository $snapshots,
        private ExportFileRepository $files,
        private ExportSourceRepository $sources,
        private SecurityEventRepository $audit,
        private CsvExportWriter $csv,
        private XlsxExportWriter $xlsx,
        private GeoJsonExportWriter $geojson,
        private ReportPdfService $pdf,
    ) {
    }

    public function forUser(User $actor, array $input, string $format): ExportArtifact
    {
        [$report] = $this->presentation->controls($input);

        return $this->generate($actor, $this->filters->forUser($actor, $input, $report, true), $report, $this->format($format, $report));
    }

    public function forPublic(array $input, string $format): ExportArtifact
    {
        [$report] = $this->presentation->controls($input);

        return $this->generate(null, $this->filters->forPublic($input, $report), $report, $this->format($format, $report));
    }

    public function treeForUser(User $actor, string $tree, array $input): ExportArtifact
    {
        $this->individualControls($input);

        return $this->generate($actor, $this->filters->forUser($actor, $input, 'inventory', true), 'inventory', ExportFormat::Pdf, $tree);
    }

    public function treeForPublic(string $organization, string $tree, array $input): ExportArtifact
    {
        $this->individualControls($input);

        return $this->generate(null, $this->filters->forPublic($input), 'inventory', ExportFormat::Pdf, $tree, $organization);
    }

    private function generate(?User $actor, ReportSelectionDTO $selection, string $report, ExportFormat $format, ?string $tree = null, ?string $organization = null): ExportArtifact
    {
        $path = null;
        try {
            $metadata = $this->presentation->metadata($actor, $selection, $report);
            // Solo criterios legibles: ni el DTO, ni la configuración de filtros, ni identificadores internos salen al archivo.
            $metadata = array_intersect_key($metadata, array_flip(['title', 'generatedAt', 'isPublic', 'scopeLabel', 'appliedFilters']));
            $manifest = [];
            $fingerprint = null;
            $path = $this->files->create();
            $count = $this->snapshots->read(function () use ($selection, $report, $format, $tree, $organization, $metadata, $path, &$manifest, &$fingerprint): int {
                $data = $this->datasets->prepare($selection, $report, $format, $tree, $organization);
                if (!in_array($report, ['inventory', 'risk_trees'], true)) {
                    $fingerprint = $this->fingerprint(['rows' => $data['rows'], 'summary' => $data['summary']]);
                }
                $rows = $this->tracked($data['rows'], $manifest);
                $document = $metadata + ['summary' => $data['summary'], 'notices' => $data['notices']];
                if ($tree !== null) {
                    $document['title'] = 'Ficha del individuo';
                }
                $written = match ($format) {
                    ExportFormat::Csv => $this->csv->write($path, $data['columns'], $rows, $document),
                    ExportFormat::Xlsx => $this->xlsx->write($path, $data['columns'], $rows, $document),
                    ExportFormat::Geojson => $this->geojson->write($path, $data['columns'], $rows, $document),
                    ExportFormat::Pdf => $this->writePdf($path, $data['columns'], iterator_to_array($rows, false), $document, $tree !== null),
                };
                if ($written !== $data['totalRows']) {
                    throw new RuntimeException('La cantidad escrita no coincide con la instantánea.');
                }

                return $written;
            });
            if ($selection->isPublic()) {
                if ($report === 'inventory') {
                    $this->sources->assertPublic($manifest);
                } else {
                    $current = $this->datasets->prepare($selection, $report, $format);
                    // Un agregado vacío también puede contener totales: se revalida toda la información publicada.
                    if ($fingerprint !== $this->fingerprint(['rows' => $current['rows'], 'summary' => $current['summary']])) {
                        throw ValidationException::withMessages(['publication' => 'La publicación cambió durante la preparación. Actualiza la consulta y descarga nuevamente.']);
                    }
                }
            } else {
                $this->access->authorize($actor, $selection, $report, true);
                $this->audit->record(
                    'reports.export_prepared',
                    $actor,
                    $selection->scope->organizationId,
                    ['resource_code' => $report, 'reason' => $format->value.':'.$count]
                );
            }

            return new ExportArtifact($path, 'sysforest-'.($tree === null ? $report : 'ficha').'.'.$format->value, $format->mime(), $count);
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->files->remove($path);
            }
            if ($exception instanceof ValidationException || $exception instanceof AuthorizationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            throw new HttpException(503, 'No fue posible preparar la descarga. Inténtalo nuevamente.', $exception);
        }
    }

    private function writePdf(string $path, array $columns, array $rows, array $metadata, bool $individual): int
    {
        $fields = [];
        if ($individual) {
            foreach ($columns as $column) {
                $fields[] = $column + ['value' => $rows[0][$column['key']] ?? null];
            }
        }

        return $this->pdf->write($path, $metadata + ['columns' => $columns, 'rows' => $rows, 'fields' => $fields], $individual);
    }

    /** Las referencias permiten comprobar revocaciones sin incluirlas entre las columnas descargables. */
    private function tracked(iterable $rows, array &$manifest): \Generator
    {
        foreach ($rows as $row) {
            $source = $row['_source'] ?? [];
            foreach (['tree_id' => 'trees', 'inspection_id' => 'inspections'] as $field => $group) {
                if (isset($source[$field])) {
                    $manifest[$group][$source[$field]] = true;
                }
            }
            foreach ($source['evidence_ids'] ?? [] as $id) {
                $manifest['evidences'][$id] = true;
            }
            yield $row;
        }
    }

    private function format(string $value, string $report): ExportFormat
    {
        $format = ExportFormat::tryFrom($value);
        if ($format === null || ($format === ExportFormat::Geojson && $report !== 'inventory')) {
            throw ValidationException::withMessages(['format' => 'Este formato no está disponible para el reporte seleccionado.']);
        }

        return $format;
    }

    private function individualControls(array $input): void
    {
        [$report] = $this->presentation->controls($input);
        if ($report !== 'inventory') {
            throw ValidationException::withMessages(['report' => 'La ficha individual utiliza el perfil del inventario.']);
        }
    }

    private function fingerprint(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
