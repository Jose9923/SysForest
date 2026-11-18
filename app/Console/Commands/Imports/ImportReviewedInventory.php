<?php

declare(strict_types=1);

namespace App\Console\Commands\Imports;

use App\Services\Imports\PreparedInventoryImportService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Entrada exclusivamente administrativa por consola; las escrituras siguen los servicios ordinarios de importación. */
class ImportReviewedInventory extends Command
{
    protected $signature = 'sysforest:import-reviewed-inventory
        {path : Ruta del JSON revisado dentro de storage/app/private}
        {--actor= : ID numérico de la cuenta responsable}
        {--organization= : UUID de la organización autorizada}
        {--inventory= : UUID de la campaña activa}
        {--confirm : Confirma los bloques solo después de validar el documento completo}';

    protected $description = 'Valida un inventario preparado sin escrituras; --confirm importa por bloques auditados y reanudables.';

    public function handle(PreparedInventoryImportService $imports): int
    {
        $actor = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $organization = (string) $this->option('organization');
        $inventory = (string) $this->option('inventory');
        if ($actor === false || !\App\Support\Identifiers\RecordIdentifier::isValid($organization) || !\App\Support\Identifiers\RecordIdentifier::isValid($inventory)) {
            $this->error('Indica --actor con ID entero positivo y --organization/--inventory con UUID válidos.');

            return self::INVALID;
        }
        try {
            $result = $imports->run(
                (string) $this->argument('path'),
                $actor,
                $organization,
                $inventory,
                (bool) $this->option('confirm'),
                static function (array $progress): void {
                    // El avance no incluye celdas, credenciales ni trazas; el JSON final conserva el resultado detallado.
                    fwrite(STDERR, $progress['phase'].' '.$progress['chunk'].'/'.$progress['total_chunks'].PHP_EOL);
                }
            );
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return in_array($result['status'], ['validated', 'completed'], true) ? self::SUCCESS : self::FAILURE;
        } catch (ValidationException $exception) {
            $this->line(json_encode(['status' => 'invalid', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->error('No se pudo preparar la importación. Comprueba archivo, cuenta, permisos, organización y campaña.');
            $this->line(json_encode(['status' => 'failed', 'exception_type' => get_class($exception)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
