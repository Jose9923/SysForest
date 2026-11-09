<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const TABLES = [
        'placements', 'establishment_origins', 'biogeographic_origins', 'growth_habits',
        'maturity_states', 'evidence_types', 'maintenance_types', 'deletion_reasons', 'fauna_categories',
    ];

    public function up(): void
    {
        // La identidad técnica permanece estable incluso ante escrituras que omitan Eloquent.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_forestry_catalog_code() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'El código del catálogo es inmutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);

        foreach (self::TABLES as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('id')->primary();
                $blueprint->string('code', 80)->unique();
                $blueprint->string('name', 180);
                $blueprint->text('description')->nullable();
                $blueprint->boolean('active')->default(true);
                $blueprint->integer('sort_order')->default(0);
                $blueprint->timestampsTz();
                $blueprint->index(['active', 'sort_order', 'name']);
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_metadata_check CHECK (sort_order >= 0 AND code ~ '^[A-Z][A-Z0-9_]{1,79}$' AND length(btrim(name)) > 0)");
            DB::unprepared("CREATE TRIGGER {$table}_stable_code BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION protect_forestry_catalog_code()");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS protect_forestry_catalog_code()');
    }
};
