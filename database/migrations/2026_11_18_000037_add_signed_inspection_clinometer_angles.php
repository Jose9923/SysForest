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
            $table->decimal('clinometer_stem_elevation_degrees', 8, 6)->nullable();
            $table->decimal('clinometer_lower_degrees', 8, 6)->nullable();
        });
        DB::statement('ALTER TABLE tree_inspections DROP CONSTRAINT inspections_clinometer_check');
        DB::statement("ALTER TABLE tree_inspections ADD CONSTRAINT inspections_clinometer_check CHECK (
            (uses_clinometer IS NOT TRUE
                AND clinometer_distance_m IS NULL AND clinometer_observer_height_m IS NULL
                AND clinometer_elevation_degrees IS NULL AND clinometer_depression_degrees IS NULL
                AND clinometer_stem_elevation_degrees IS NULL AND clinometer_lower_degrees IS NULL
                AND clinometer_formula_version IS NULL)
            OR (uses_clinometer IS TRUE
                AND clinometer_distance_m IS NOT NULL AND clinometer_distance_m > 0 AND clinometer_distance_m <> 'NaN'::numeric
                AND clinometer_elevation_degrees IS NOT NULL AND clinometer_elevation_degrees >= 0 AND clinometer_elevation_degrees < 90
                AND total_height_m IS NOT NULL AND commercial_height_m IS NOT NULL
                AND clinometer_formula_version IS NOT NULL
                AND (
                    (clinometer_formula_version = 'INCLINOMETER_V1'
                        AND clinometer_observer_height_m IS NOT NULL AND clinometer_observer_height_m > 0 AND clinometer_observer_height_m <> 'NaN'::numeric
                        AND clinometer_depression_degrees IS NOT NULL AND clinometer_depression_degrees >= 0 AND clinometer_depression_degrees < 90
                        AND clinometer_stem_elevation_degrees IS NULL AND clinometer_lower_degrees IS NULL)
                    OR (clinometer_formula_version = 'INCLINOMETER_V2'
                        AND clinometer_observer_height_m IS NULL AND clinometer_depression_degrees IS NULL
                        AND clinometer_stem_elevation_degrees IS NOT NULL AND clinometer_stem_elevation_degrees >= 0 AND clinometer_stem_elevation_degrees < 90
                        AND clinometer_lower_degrees IS NOT NULL AND clinometer_lower_degrees > -90 AND clinometer_lower_degrees < 90
                        AND total_height_m > 0 AND commercial_height_m >= 0 AND commercial_height_m <= total_height_m)
                ))
        )");
    }

    public function down(): void
    {
        if (DB::table('tree_inspections')->where('clinometer_formula_version', 'INCLINOMETER_V2')->exists()) {
            throw new LogicException('No se pueden retirar lecturas V2 existentes. Restaura un respaldo coherente si necesitas revertir la aplicación.');
        }
        DB::statement('ALTER TABLE tree_inspections DROP CONSTRAINT inspections_clinometer_check');
        Schema::table('tree_inspections', function (Blueprint $table): void {
            $table->dropColumn(['clinometer_stem_elevation_degrees', 'clinometer_lower_degrees']);
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
};
