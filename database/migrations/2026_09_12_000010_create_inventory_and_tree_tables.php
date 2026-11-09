<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->integer('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'active', 'status']);
        });

        Schema::create('organization_sequences', function (Blueprint $table): void {
            // El valor por defecto permite que el incremento atómico use INSERT ... RETURNING.
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 40);
            $table->bigInteger('value')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('trees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->string('external_number', 100)->nullable();
            $table->foreignUuid('species_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('placement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('growth_habit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('establishment_origin_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('biogeographic_origin_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('location_description')->nullable();
            $table->text('coordinate_raw')->nullable();
            $table->string('coordinate_format', 20)->nullable();
            $table->string('datum', 80)->nullable();
            $table->decimal('accuracy_m', 12, 3)->nullable();
            $table->string('gps_brand', 100)->nullable();
            $table->string('gps_model', 100)->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->string('current_status', 30)->default('active');
            $table->boolean('is_protected')->default(false);
            $table->boolean('is_heritage')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->integer('lock_version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('deletion_reason_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('deletion_notes')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('restored_at')->nullable();
            $table->unique(['organization_id', 'id']);
            // Incluye los anulados: su código humano nunca vuelve a quedar disponible.
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'species_id']);
            $table->index(['organization_id', 'placement_id']);
            $table->index(['organization_id', 'current_status']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'deleted_at']);
        });
        DB::statement('ALTER TABLE trees ADD COLUMN geometry geometry(Point,4326)');
        DB::statement('CREATE INDEX trees_geometry_gist ON trees USING GIST (geometry)');
        // Las búsquedas métricas y las cajas geográficas usan clases de operadores distintas.
        DB::statement('CREATE INDEX trees_geography_gist ON trees USING GIST ((geometry::geography))');

        Schema::create('inventory_trees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('inventory_id');
            $table->uuid('tree_id');
            $table->timestampTz('included_at');
            $table->foreignId('included_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign(['organization_id', 'inventory_id'])->references(['organization_id', 'id'])->on('inventories')->restrictOnDelete();
            $table->foreign(['organization_id', 'tree_id'])->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->unique(['inventory_id', 'tree_id']);
            $table->index(['organization_id', 'inventory_id']);
            $table->index(['organization_id', 'tree_id']);
        });

        Schema::create('tree_status_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('tree_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
            $table->foreign(['organization_id', 'tree_id'])->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->index(['organization_id', 'tree_id', 'changed_at']);
        });

        Schema::create('tree_location_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('tree_id');
            $table->text('old_coordinate_raw')->nullable();
            $table->text('new_coordinate_raw')->nullable();
            $table->jsonb('old_metadata')->default('{}');
            $table->jsonb('new_metadata')->default('{}');
            // NULL identifica la captura inicial; los cambios posteriores tienen causa explícita.
            $table->string('change_type', 20)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
            $table->foreign(['organization_id', 'tree_id'])->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->index(['organization_id', 'tree_id', 'changed_at']);
        });
        DB::statement('ALTER TABLE tree_location_histories ADD COLUMN old_geometry geometry(Point,4326)');
        DB::statement('ALTER TABLE tree_location_histories ADD COLUMN new_geometry geometry(Point,4326)');

        $statuses = "'active','under_observation','dead','felled','transplanted','removed','replaced'";
        DB::statement("ALTER TABLE inventories ADD CONSTRAINT inventories_values_check CHECK (
            code ~ '^[A-Z0-9]+([-_][A-Z0-9]+)*$' AND length(btrim(name)) > 0
            AND status IN ('draft','active','closed','archived') AND lock_version >= 1
            AND (starts_at IS NULL OR ends_at IS NULL OR ends_at >= starts_at)
        )");
        DB::statement("ALTER TABLE organization_sequences ADD CONSTRAINT organization_sequences_values_check CHECK (
            value >= 0 AND name ~ '^[a-z][a-z0-9_]{0,39}$'
        )");
        DB::statement("ALTER TABLE trees ADD CONSTRAINT trees_values_check CHECK (
            code ~ '^[A-Z0-9]+(-[A-Z0-9]+)*$'
            AND current_status IN ({$statuses}) AND lock_version >= 1
            AND (coordinate_format IS NULL OR coordinate_format IN ('decimal','dms'))
            AND (accuracy_m IS NULL OR (accuracy_m >= 0 AND accuracy_m <> 'NaN'::numeric))
            AND (deleted_at IS NULL OR (deleted_by IS NOT NULL AND deletion_reason_id IS NOT NULL))
            AND (restored_at IS NULL OR restored_by IS NOT NULL)
        )");
        DB::statement("ALTER TABLE tree_status_histories ADD CONSTRAINT tree_status_histories_values_check CHECK (
            to_status IN ({$statuses}) AND (from_status IS NULL OR from_status IN ({$statuses}))
            AND (from_status IS NULL OR from_status <> to_status)
        )");
        DB::statement("ALTER TABLE tree_location_histories ADD CONSTRAINT tree_location_histories_values_check CHECK (
            (change_type IS NULL OR change_type IN ('correction','relocation','transplant'))
            AND jsonb_typeof(old_metadata) = 'object' AND jsonb_typeof(new_metadata) = 'object'
        )");
        DB::statement('CREATE UNIQUE INDEX tree_status_initial_unique ON tree_status_histories (organization_id,tree_id) WHERE from_status IS NULL');
        DB::statement('CREATE UNIQUE INDEX tree_location_initial_unique ON tree_location_histories (organization_id,tree_id) WHERE change_type IS NULL');

        foreach ([['trees', 'geometry'], ['tree_location_histories', 'old_geometry'], ['tree_location_histories', 'new_geometry']] as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK (
                {$column} IS NULL OR (
                    NOT ST_IsEmpty({$column}) AND ST_IsValid({$column})
                    AND ST_X({$column}) BETWEEN -180 AND 180
                    AND ST_Y({$column}) BETWEEN -90 AND 90
                )
            )");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.protect_forestry_identity() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Los individuos se anulan; no se eliminan físicamente.' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.id, NEW.organization_id, NEW.created_by) IS DISTINCT FROM ROW(OLD.id, OLD.organization_id, OLD.created_by) THEN
        RAISE EXCEPTION 'La identidad, organización y autor original son inmutables.' USING ERRCODE = '23514';
    END IF;
    IF TG_TABLE_NAME = 'trees' AND NEW.code IS DISTINCT FROM OLD.code THEN
        RAISE EXCEPTION 'El código del individuo es inmutable.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER trees_identity_immutable BEFORE UPDATE OR DELETE ON trees
FOR EACH ROW EXECUTE FUNCTION public.protect_forestry_identity();
CREATE TRIGGER inventories_identity_immutable BEFORE UPDATE ON inventories
FOR EACH ROW EXECUTE FUNCTION public.protect_forestry_identity();

CREATE OR REPLACE FUNCTION public.protect_forestry_history() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'El historial forestal solo admite nuevos registros.' USING ERRCODE = '23514';
END;
$body$;

CREATE TRIGGER tree_status_history_append_only BEFORE UPDATE OR DELETE ON tree_status_histories
FOR EACH ROW EXECUTE FUNCTION public.protect_forestry_history();
CREATE TRIGGER tree_location_history_append_only BEFORE UPDATE OR DELETE ON tree_location_histories
FOR EACH ROW EXECUTE FUNCTION public.protect_forestry_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_location_histories');
        Schema::dropIfExists('tree_status_histories');
        Schema::dropIfExists('inventory_trees');
        Schema::dropIfExists('trees');
        Schema::dropIfExists('organization_sequences');
        Schema::dropIfExists('inventories');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_forestry_history()');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_forestry_identity()');
    }
};
