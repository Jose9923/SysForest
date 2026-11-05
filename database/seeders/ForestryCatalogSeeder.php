<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Catalogs\CatalogRegistry;
use App\Repositories\Catalogs\CatalogRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Valores iniciales trazados en docs/forestry-catalogs.md; no sobrescribe decisiones administrativas. */
class ForestryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalogs = app(CatalogRegistry::class);
        $repository = app(CatalogRepository::class);
        $data = [
            'placements' => [
                ['PLC_SIDEWALK', 'Andén'],
                ['PLC_PARK', 'Parque'],
                ['PLC_ROAD_MEDIAN', 'Separador vial'],
                ['PLC_GREEN_AREA', 'Zona verde'],
                ['PLC_INSTITUTION', 'Institución'],
                ['PLC_PUBLIC_ROAD', 'Vía pública'],
            ],
            'establishment-origins' => [
                ['EST_SPONTANEOUS', 'Espontánea'],
                ['EST_PLANTED', 'Plantada'],
            ],
            'biogeographic-origins' => [
                ['BIO_NATIVE', 'Especie nativa'],
                ['BIO_EXOTIC', 'Especie exótica'],
                ['BIO_NATURALIZED', 'Especie naturalizada'],
                ['BIO_INVASIVE', 'Especie invasora'],
            ],
            'growth-habits' => [
                ['HAB_TREE', 'Árbol'],
                ['HAB_PALM', 'Palma'],
                ['HAB_SHRUB', 'Arbusto'],
                ['HAB_CANE_GRASS', 'Caña o pasto'],
                ['HAB_SHOOT', 'Vástago'],
                ['HAB_VINE', 'Bejuco'],
                ['HAB_LIANA', 'Liana'],
                ['HAB_NONPARASITIC_EPIPHYTE', 'Epífita no parásita'],
                ['HAB_PARASITIC_EPIPHYTE', 'Epífita parásita'],
                ['HAB_STRANGLER', 'Matapalo'],
                ['HAB_HERBACEOUS', 'Herbácea'],
            ],
            'maturity-states' => [
                ['MAT_YOUNG', 'Fustal joven'],
                ['MAT_MEDIUM', 'Fustal medio'],
                ['MAT_MATURE', 'Fustal maduro'],
                ['MAT_OVERMATURE', 'Fustal sobremaduro'],
            ],
            'fauna-categories' => [
                ['FAU_BIRDS', 'Aves'],
                ['FAU_MAMMALS', 'Mamíferos'],
                ['FAU_REPTILES', 'Reptiles'],
                ['FAU_INSECTS', 'Insectos'],
                ['FAU_BATS', 'Quirópteros'],
                ['FAU_OTHER', 'Otros'],
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
