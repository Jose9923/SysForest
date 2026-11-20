<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\Trees\TreeStatus;
use App\Repositories\Imports\ReferenceInventoryImportRepository;
use App\Repositories\Imports\ReferenceInventorySeedRepository;
use App\Repositories\Users\UserRepository;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use App\Services\Forestry\ForestryAccessService;
use App\Services\Risk\HistoricalRiskImportScope;
use App\Services\Tenancy\TenantContextService;
use App\Services\Trees\TreeLifecycleService;

/** Recuperación local de un único libro autorizado; no clasifica nuevas visitas ni otras importaciones. */
class ReferenceInventoryImportService
{
    public const SOURCE_SHA256 = '54398c5db028c94652bc562d574651303d9dba707e454607aa268592c57ace63';

    public const INVENTORY_CODE = 'REFERENCE-2025';

    public const LEGACY_INVENTORY_CODE_HASH = 'c78c06b610a3820b191d53fd7182e252ad6361dfe2ca77e5ae7bb0a677e222bb';

    private const FILES = [
        'source.xlsx' => self::SOURCE_SHA256,
        'prepared.json' => '18a7660baa3dd04457d6408a5e8dc7829e948b515ddfdb86090f573818b2583a',
        'statuses.json' => '5147336caa9d11dba46f77579238e5ba0ea066456529e38fcc538b7a3ddbf62c',
    ];

    public function __construct(
        private PreparedInventoryImportService $imports,
        private ReferenceInventorySeedRepository $destinations,
        private ReferenceInventoryImportRepository $records,
        private UserRepository $users,
        private TenantContextService $tenants,
        private TreeLifecycleService $trees,
        private ForestryAccessService $access,
    ) {
    }

    /** Falla antes de sembrar si falta la fuente; nunca informa una instalación completa sin árboles. */
    public function assertFiles(): void
    {
        if (!app()->runningInConsole() || !app()->environment('local')) {
            throw new LogicException('La carga de referencia solo está autorizada en el entorno local por consola.');
        }
        if ((int) config('imports.max_rows') < 100) {
            throw new LogicException('La carga de referencia requiere bloques de 100 filas para conservar sus 29 lotes.');
        }
        foreach (self::FILES as $name => $hash) {
            $path = $this->sourcePath($name);
            if (!is_file($path) || !is_readable($path) || !hash_equals($hash, hash_file('sha256', $path))) {
                throw new LogicException('Falta el archivo autorizado o cambió su huella: database/seeders/data/private-restrepo/'.$name
                    .'. Recupera el paquete privado original; no se omitirá la importación.');
            }
        }
    }

    /** @param Closure(array):void|null $progress Avance sin contenido privado de las celdas. */
    public function run(?Closure $progress = null): array
    {
        $this->assertFiles();
        $lock = Cache::lock('seed:reference-inventory:'.self::SOURCE_SHA256, 3600);
        if (!$lock->get()) {
            throw new LogicException('Ya se está ejecutando la carga local de referencia. Espera a que termine.');
        }
        try {
            $actor = $this->users->findByEmail('superadmin@sysforest.test')
                ?? throw new LogicException('Prepara primero las cuentas locales.');
            $organization = $this->destinations->organization('RESTREPO-META')
                ?? throw new LogicException('Prepara primero la organización de Restrepo.');
            $inventory = $this->destinations->inventory($organization->id, self::INVENTORY_CODE, self::LEGACY_INVENTORY_CODE_HASH)
                ?? throw new LogicException('Prepara primero la campaña de referencia.');
            if ($organization->is_public || $inventory->is_public) {
                throw new LogicException('La carga autorizada de La carga de referencia requiere organización y campaña privadas.');
            }
            $relative = 'reference-inventory-seed/prepared.json';
            $disk = Storage::disk('local');
            $content = file_get_contents($this->sourcePath('prepared.json'));
            if (!$disk->put($relative, $content)) {
                throw new LogicException('No se pudo conservar la copia revisada en almacenamiento privado.');
            }
            $report = app(HistoricalRiskImportScope::class)->recoverReference(
                $organization,
                $inventory,
                fn () => $this->imports->run($disk->path($relative), (int) $actor->id, $organization->id, $inventory->id, true, $progress)
            );
            if ($report['status'] !== 'completed') {
                throw new LogicException('La carga de referencia no se completó: '.json_encode($report['errors'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            if ($report['completed_rows'] !== 2854 || $report['total_chunks'] !== 29) {
                throw new LogicException('La conciliación de referencia no corresponde a 2.854 filas en 29 lotes.');
            }
            $manifest = json_decode(file_get_contents($this->sourcePath('statuses.json')), true, flags: JSON_THROW_ON_ERROR);
            $changed = $this->tenants->run($actor, $organization->id, function () use ($actor, $organization, $report, $manifest): int {
                return DB::transaction(function () use ($actor, $organization, $report, $manifest): int {
                    // Conserva el orden de bloqueo del flujo de escritura: actor, organización y árbol.
                    $actor = $this->users->findForUpdate((int) $actor->id);
                    $this->access->organization($actor, true);
                    $rows = $this->records->importedRows($organization->id, array_column($report['chunks'], 'batch_id'));
                    if ($rows->count() !== 2854 || $rows->pluck('tree_id')->unique()->count() !== 2854) {
                        throw new LogicException('Las referencias importadas de referencia no concuerdan.');
                    }
                    $changed = 0;
                    foreach ($manifest['rows'] as $index => $target) {
                        $row = $rows[$index];
                        if ($row->row_number !== $target['row_number'] || $row->status !== 'imported'
                            || $row->raw_values['external_number'] !== $target['external_number']) {
                            throw new LogicException('La fila revisada de referencia no coincide con su importación.');
                        }
                        if ($target['status'] === 'active') {
                            continue;
                        }
                        $reason = '[Referencia '.self::SOURCE_SHA256.'] '.\App\Support\Imports\ExcelReferencePresentation::text($target['reason']);
                        $tree = $this->records->lockTree($organization->id, $row->tree_id);
                        // El historial acredita la aplicación: no se revierten decisiones posteriores del usuario.
                        if ($this->records->correctionExists($organization->id, $tree->id, $reason, $target['status'], $target['reason'])) {
                            continue;
                        }
                        $inspection = $this->records->latestInspection($organization->id, $tree->id);
                        if ($tree->trashed() || $tree->current_status !== TreeStatus::Active || $tree->lock_version !== 1
                            || $tree->external_number !== $target['external_number'] || $inspection?->id !== $row->inspection_id
                            || $inspection->lock_version !== 1 || $inspection->inspection_date->toDateString() !== '2025-11-30') {
                            throw new LogicException('El árbol de la fila '.$row->row_number.' cambió; requiere revisión independiente.');
                        }
                        $this->trees->status($actor, $tree->id, [
                            'lock_version' => $tree->lock_version, 'current_status' => $target['status'], 'reason' => $reason,
                        ]);
                        $changed++;
                    }

                    return $changed;
                });
            });

            return [
                'imported' => $report['confirmed_rows'], 'already_imported' => $report['skipped_completed_rows'],
                'batches' => $report['total_chunks'], 'status_changes' => $changed,
            ];
        } finally {
            $lock->release();
        }
    }

    /** Identidad acotada a la campaña original o su nombre neutral para una alta nueva. */
    public static function matchesInventoryCode(string $code): bool
    {
        return $code === self::INVENTORY_CODE || hash_equals(self::LEGACY_INVENTORY_CODE_HASH, hash('sha256', $code));
    }

    private function sourcePath(string $name): string
    {
        return database_path('seeders/data/private-restrepo/'.$name);
    }
}
