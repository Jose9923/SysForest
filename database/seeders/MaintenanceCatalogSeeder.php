<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Catalogs\CatalogRegistry;
use App\Repositories\CatalogRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Valores iniciales trazados en docs/forestry-catalogs.md; no sobrescribe decisiones administrativas. */
class MaintenanceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalogs = app(CatalogRegistry::class);
        $repository = app(CatalogRepository::class);
        $data = [
            'maintenance-types' => [
                ['MNT_PRUNING', 'Poda'],
                ['MNT_FELLING', 'Tala'],
                ['MNT_PHYTOSANITARY', 'Tratamiento fitosanitario'],
                ['MNT_HERBICIDE', 'Aplicación de herbicida'],
                ['MNT_FERTILIZATION', 'Fertilización'],
                ['MNT_IRRIGATION', 'Riego'],
                ['MNT_PEST_CONTROL', 'Control de plagas'],
                ['MNT_ROOT_MANAGEMENT', 'Manejo de raíces'],
                ['MNT_TRANSPLANT', 'Traslado'],
                ['MNT_TECHNICAL_INSPECTION', 'Inspección técnica'],
                ['MNT_OTHER', 'Otro'],
            ],
            'deletion-reasons' => [
                ['DEL_DUPLICATE', 'Registro duplicado'],
                ['DEL_NONEXISTENT_TREE', 'Árbol inexistente'],
                ['DEL_LOCATION_ERROR', 'Error de ubicación'],
                ['DEL_IDENTIFICATION_ERROR', 'Error de identificación'],
                ['DEL_TEST_RECORD', 'Registro de prueba'],
                ['DEL_ACCIDENTAL_CREATION', 'Creación accidental'],
                ['DEL_OTHER', 'Otro'],
            ],
        ];

        DB::transaction(function () use ($data, $catalogs, $repository): void {
            foreach ($data as $key => $values) {
                $catalog = $catalogs->find($key);
                foreach ($values as $index => [$code, $name]) {
                    $repository->seed($catalog, $code, ['name' => $name, 'active' => true, 'sort_order' => ($index + 1) * 10]);
                }
            }
        });
    }
}
