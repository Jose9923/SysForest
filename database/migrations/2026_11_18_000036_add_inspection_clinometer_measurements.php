<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('tree_inspections', function (Blueprint $table): void {
            // NULL identifica antecedentes sin este método, sin asignarles observaciones nuevas.
            $table->boolean('uses_clinometer')->nullable();
            foreach (['clinometer_distance_m', 'clinometer_observer_height_m'] as $field) {
                $table->decimal($field, 18, 6)->nullable();
            }
            foreach (['clinometer_elevation_degrees', 'clinometer_depression_degrees'] as $field) {
                $table->decimal($field, 8, 6)->nullable();
            }
            $table->string('clinometer_formula_version', 40)->nullable();
        });
        DB::statement("ALTER TABLE tree_inspections ADD CONSTRAINT inspections_clinometer_check CHECK (
            (uses_clinometer IS NOT TRUE
                AND clinometer_distance_m IS NULL AND clinometer_observer_height_m IS NULL
                AND clinometer_elevation_degrees IS NULL AND clinometer_depression_degrees IS NULL
                AND clinometer_formula_version IS NULL)
            OR (uses_clinometer IS TRUE
                AND clinometer_distance_m IS NOT NULL AND clinometer_distance_m > 0 AND clinometer_distance_m <> 'NaN'::numeric
                AND clinometer_observer_height_m IS NOT NULL AND clinometer_observer_height_m > 0 AND clinometer_observer_height_m <> 'NaN'::numeric
                AND clinometer_elevation_degrees IS NOT NULL AND clinometer_elevation_degrees >= 0 AND clinometer_elevation_degrees < 90
                AND clinometer_depression_degrees IS NOT NULL AND clinometer_depression_degrees >= 0 AND clinometer_depression_degrees < 90
                AND clinometer_formula_version IS NOT NULL AND clinometer_formula_version = 'INCLINOMETER_V1'
                AND total_height_m IS NOT NULL AND commercial_height_m IS NOT NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tree_inspections DROP CONSTRAINT inspections_clinometer_check');
        Schema::table('tree_inspections', function (Blueprint $table): void {
            $table->dropColumn(['uses_clinometer', 'clinometer_distance_m', 'clinometer_observer_height_m',
                'clinometer_elevation_degrees', 'clinometer_depression_degrees', 'clinometer_formula_version']);
        });
    }
};
