<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Taxonomy\TaxonomyCatalog;
use App\Repositories\Taxonomy\TaxonomyRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxonomySeeder extends Seeder
{
    /** Los literales de desarrollo están respaldados; no se reescriben ediciones nacionales. */
    public function run(TaxonomyRepository $repository): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/taxonomy.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($data, $repository): void {
            $families = [];
            foreach ($data['families'] as $row) {
                $families[$row['code']] = $repository->seed(TaxonomyCatalog::Families, $row['code'], $row + ['active' => true]);
            }
            foreach ($data['species'] as $row) {
                $values = array_diff_key($row, array_flip(['family_code', 'common_name']));
                $species = $repository->seed(TaxonomyCatalog::Species, $row['code'], $values + ['family_id' => $families[$row['family_code']]->id, 'active' => true]);
                $common = $row['common_name'];
                // Si un administrador ya cambió el nombre principal, no se impone otro al reseed.
                $common['is_primary'] = ! $repository->conflictingPrimary($species->id, null);
                $repository->seed(TaxonomyCatalog::CommonNames, $common['code'], $common + [
                    'species_id' => $species->id, 'sort_order' => 10, 'active' => true, 'description' => $row['description'],
                ]);
            }
        });
    }
}
