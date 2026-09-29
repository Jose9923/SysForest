<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const TABLES = [
        'organizations', 'placements', 'establishment_origins', 'biogeographic_origins',
        'growth_habits', 'maturity_states', 'evidence_types', 'maintenance_types',
        'deletion_reasons', 'fauna_categories', 'families', 'species', 'species_common_names',
        'condition_categories', 'conditions', 'classification_rule_versions',
    ];

    public function up(): void
    {
        // El valor inicial versiona registros existentes sin cambiar su identidad ni contenido.
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $definition) => $definition->integer('lock_version')->default(1));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_lock_version_positive CHECK (lock_version >= 1)");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, fn (Blueprint $definition) => $definition->dropColumn('lock_version'));
        }
    }
};
