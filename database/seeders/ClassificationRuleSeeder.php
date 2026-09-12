<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\ClassificationRuleRepository;
use Illuminate\Database\Seeder;

class ClassificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        app(ClassificationRuleRepository::class)->seedExcelVersion();
    }
}
