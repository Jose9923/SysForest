<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Catalogs\CatalogRegistry;
use App\Repositories\CatalogRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Valores iniciales trazados en docs/forestry-catalogs.md; no sobrescribe decisiones administrativas. */
class EvidenceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $catalogs = app(CatalogRegistry::class);
        $repository = app(CatalogRepository::class);
        $data = [
            'evidence-types' => [
                ['EVD_WHOLE_TREE', 'Individuo completo'],
                ['EVD_ROOT_SYSTEM', 'Sistema radicular'],
                ['EVD_STEM', 'Fuste'],
                ['EVD_BRANCHES', 'Ramas'],
                ['EVD_CROWN', 'Copa'],
                ['EVD_FOLIAGE', 'Follaje'],
                ['EVD_FLOWERS_FRUITS', 'Flores/frutos'],
                ['EVD_PHYTOSANITARY', 'Afectación fitosanitaria'],
                ['EVD_FAUNA', 'Fauna'],
                ['EVD_MAINTENANCE_BEFORE', 'Mantenimiento antes'],
                ['EVD_MAINTENANCE_AFTER', 'Mantenimiento después'],
                ['EVD_OTHER', 'Otro'],
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
