<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\DTOs\Reports\ReportSelectionDTO;
use App\Reports\ExportFormat;
use App\Reports\InventoryExportSchema;
use App\Reports\RiskTreeSchema;
use App\Repositories\Reports\ExportReadRepository;
use App\Repositories\Maps\MapTreeRepository;
use App\Repositories\Reports\ReportRepository;
use Illuminate\Validation\ValidationException;

/** Define filas y límites antes de escribir; debe consumirse dentro de la instantánea del reporte. */
class ExportDatasetService
{
    public function __construct(
        private ExportReadRepository $trees,
        private ReportRepository $reports,
        private MapTreeRepository $mapTrees,
        private InventoryExportSchema $schema,
        private ReportReadService $presentation,
        private RiskTreeSchema $riskSchema,
    ) {
    }

    public function prepare(ReportSelectionDTO $selection, string $report, ExportFormat $format, ?string $tree = null, ?string $organization = null): array
    {
        abort_if($report === 'risk_trees' && $selection->isPublic(), 404);
        $summary = $this->mapTrees->summary($selection->scope, $selection->filters);
        if ($tree !== null) {
            $row = $this->trees->find($selection, $tree, $organization);
            abort_if($row === null, 404);
            $data = ['rows' => [$row], 'columns' => $this->schema->columns($selection->isPublic()),
                'totalRows' => 1, 'notices' => ['Ficha del individuo y su última inspección pertinente. No sustituye el historial de visitas.']];
            $summary = ['total' => 1, 'georeferenced' => $row['_geometry'] === null ? 0 : 1,
                'without_geometry' => $row['_geometry'] === null ? 1 : 0];
        } elseif ($report === 'risk_trees') {
            $total = $this->trees->count($selection);
            $this->checkLimit($selection, $format, $total);
            $data = ['rows' => $this->trees->rows($selection, (int) config('reports.batch_size', 200), riskOnly: true),
                'columns' => $this->riskSchema->columns(detail: $format !== ExportFormat::Pdf),
                'totalRows' => $total, 'notices' => $this->riskSchema->notices()];
            if ($format === ExportFormat::Pdf) {
                $data['notices'][] = 'El PDF resume la bandeja. Excel y CSV incluyen además la explicación, observaciones y datos de confirmación disponibles.';
            }
        } elseif ($report === 'inventory') {
            $total = $this->trees->count($selection);
            $this->checkLimit($selection, $format, $total);
            $data = ['rows' => $this->trees->rows($selection, (int) config('reports.batch_size', 200)),
                'columns' => $format === ExportFormat::Pdf ? $this->presentation->inventoryColumns($selection->isPublic())
                    : $this->schema->columns($selection->isPublic()), 'totalRows' => $total,
                'notices' => ['Una fila por individuo; mediciones de la última inspección pertinente.',
                    'El encuadre del mapa no limita este reporte. Los filtros y el radio explícito sí se aplican.']];
            if ($format === ExportFormat::Pdf) {
                $data['notices'][] = 'Este PDF presenta la tabla resumida. La ficha individual contiene el perfil completo; Excel, CSV y GeoJSON incluyen todas las columnas permitidas.';
            }
        } else {
            $data = $this->reports->aggregate($selection, $report);
        }
        $this->checkLimit($selection, $format, $data['totalRows']);
        $data['summary'] = ['totalTrees' => $summary['total'], 'totalRows' => $data['totalRows'],
            'georeferenced' => $summary['georeferenced'], 'withoutGeometry' => $summary['without_geometry']];

        return $data;
    }

    /** Se rechaza el conjunto entero: nunca se entrega un archivo parcial como si fuera completo. */
    private function checkLimit(ReportSelectionDTO $selection, ExportFormat $format, int $rows): void
    {
        $maximum = (int) config($selection->isPublic() ? 'reports.max_export_rows_public' : 'reports.max_export_rows_private');
        if ($format === ExportFormat::Pdf) {
            $maximum = min($maximum, (int) config('reports.max_pdf_rows', 200));
        }
        if ($rows > $maximum) {
            throw ValidationException::withMessages(['export' => 'La consulta contiene '.$rows.' filas y supera el límite de '.$maximum.' para '.$format->label().'. Aplica más filtros o elige un formato con mayor capacidad.']);
        }
    }
}
