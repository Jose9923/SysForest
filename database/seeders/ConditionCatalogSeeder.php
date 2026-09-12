<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConditionCatalogKind;
use App\Repositories\ConditionCatalogRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConditionCatalogSeeder extends Seeder
{
    /** CE y CF son fenología estructurada: no se convierten en condiciones. */
    public function run(ConditionCatalogRepository $repository): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/condition-catalogs.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($data, $repository): void {
            $categories = [];
            foreach ($data['categories'] as $row) {
                $categories[$row['code']] = $repository->seed(ConditionCatalogKind::Categories, $row['code'], $row + ['active' => true]);
            }
            foreach ($data['conditions'] as $row) {
                $values = array_diff_key($row, ['category_code' => true]);
                $repository->seed(ConditionCatalogKind::Items, $row['code'], $values + [
                    'condition_category_id' => $categories[$row['category_code']]->id, 'active' => true,
                ]);
            }
        });
    }
}
