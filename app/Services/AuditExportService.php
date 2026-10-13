<?php

declare(strict_types=1);

namespace App\Services;

use App\Auditing\AuditCatalog;
use App\DTOs\AuditScope;
use App\DTOs\ExportArtifact;
use App\Models\User;
use App\Reports\Writers\AuditXlsxWriter;
use App\Repositories\AuditSourceRepository;
use App\Repositories\ExportFileRepository;
use App\Repositories\ReportSnapshotRepository;
use App\Repositories\SecurityEventRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Exporta la consulta completa desde el historial transaccional, con alcance y filtros compartidos. */
class AuditExportService
{
    public function __construct(
        private AuditAccessService $access,
        private AuditFilterService $filters,
        private AuditPresentationService $presentation,
        private AuditSourceRepository $sources,
        private ReportSnapshotRepository $snapshots,
        private ExportFileRepository $files,
        private SecurityEventRepository $audit,
        private AuditXlsxWriter $writer,
    ) {
    }

    public function forUser(User $actor, array $input): ExportArtifact
    {
        $input = $this->filters->validate($input);
        $scope = $this->access->resolve($actor, $input['scope'] ?? null);
        $filters = $this->filters->resolve($input, $scope);
        unset($filters['page']);
        $path = null;

        try {
            $path = $this->files->create();
            $count = $this->snapshots->read(function () use ($path, $scope, $filters): int {
                $total = $this->sources->exportCount($scope, $filters);
                $limit = max(1, min(50000, (int) config('audit.max_export_rows', 50000)));
                if ($total > $limit) {
                    throw ValidationException::withMessages([
                        'export' => 'La consulta contiene '.number_format($total, 0, ',', '.').' registros y supera el límite de '
                            .number_format($limit, 0, ',', '.').'. Reduce el rango de fechas o aplica más filtros para descargarla completa.',
                    ]);
                }
                $count = $this->writer->write(
                    $path,
                    $this->entries($this->sources->exportRows($scope, $filters)),
                    $this->criteria($scope, $filters),
                );
                if ($count !== $total) {
                    throw new RuntimeException('La cantidad exportada no coincide con la instantánea de auditoría.');
                }

                return $count;
            });
            // La segunda validación ocurre fuera de la instantánea, inmediatamente antes de entregar el archivo.
            $this->access->reauthorize($actor, $scope);
            $this->audit->record('audit.export_prepared', $actor, $scope->organizationId, [
                'resource_code' => $scope->kind, 'reason' => 'xlsx:'.$count,
                'department_id' => count($scope->departmentIds) === 1 ? $scope->departmentIds[0] : null,
            ]);

            return new ExportArtifact(
                $path,
                'sysforest-auditoria-'.$filters['date_from'].'-a-'.$filters['date_to'].'.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $count,
            );
        } catch (Throwable $exception) {
            if ($path !== null) {
                $this->files->remove($path);
            }
            if ($exception instanceof ValidationException || $exception instanceof AuthorizationException
                || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            // Los errores de un driver pueden contener consultas, direcciones y credenciales: no se registran en bruto.
            Log::warning('No fue posible preparar la exportación privada de auditoría.', ['exception_type' => $exception::class]);
            throw new HttpException(503, 'No fue posible preparar el Excel de auditoría. Inténtalo nuevamente.');
        }
    }

    private function entries(iterable $records): \Generator
    {
        foreach ($records as $record) {
            yield $this->presentation->detail($record);
        }
    }

    private function criteria(AuditScope $scope, array $filters): array
    {
        $criteria = [
            'Reporte' => 'Auditoría de actividad de SYSFOREST',
            'Generado (UTC)' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'Alcance' => $scope->label(),
            'Desde (inclusive, UTC)' => $filters['date_from'],
            'Hasta (inclusive, UTC)' => $filters['date_to'],
        ];
        foreach ([
            'organization_id' => 'Organización (identificador)', 'user_id' => 'Usuario (identificador)',
            'actor' => 'Nombre del usuario contiene', 'event' => 'Acción', 'module' => 'Módulo',
            'resource_type' => 'Tipo de recurso', 'resource_id' => 'Recurso (identificador)',
        ] as $key => $label) {
            if (!isset($filters[$key])) {
                continue;
            }
            $criteria[$label] = match ($key) {
                'event' => AuditCatalog::eventLabel($filters[$key]).' ['.$filters[$key].']',
                'module' => AuditCatalog::modules()[$filters[$key]] ?? $filters[$key],
                'resource_type' => AuditPresentationService::TYPES[$filters[$key]] ?? $filters[$key],
                default => (string) $filters[$key],
            };
        }
        $criteria['Contenido'] = 'Todos los eventos que cumplen los filtros, no solo la página visible. Los cambios se relacionan por el identificador del registro.';
        $criteria['Fechas'] = 'Las fechas y horas se muestran en UTC; el rango incluye el día inicial y el día final completos.';
        $criteria['Historial'] = 'Instantánea consistente del registro transaccional que alimenta MongoDB. Solo incluye atributos permitidos de auditoría.';
        $criteria['Nombres'] = 'Los nombres de usuarios y organizaciones corresponden a sus valores actuales; los identificadores conservan la referencia.';
        $criteria['Cambios'] = 'Un evento puede no tener valores anteriores y posteriores. La hoja Cambios incluye únicamente diferencias disponibles.';
        $criteria['Precisión'] = 'Las celdas son texto para conservar los identificadores y la precisión de las fechas. No contiene fórmulas.';

        return $criteria;
    }
}
