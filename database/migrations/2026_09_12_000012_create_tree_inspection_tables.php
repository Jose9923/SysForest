<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('inventory_trees', function (Blueprint $table): void {
            // La inspección debe apuntar a una participación del mismo individuo, no solo del tenant.
            $table->unique(['organization_id', 'tree_id', 'id'], 'inventory_trees_inspection_identity_unique');
        });

        Schema::create('tree_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('tree_id');
            $table->uuid('inventory_tree_id')->nullable();
            $table->date('inspection_date');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->integer('lock_version')->default(1);
            foreach (['cap_cm', 'total_height_m', 'commercial_height_m', 'crown_x_m', 'crown_y_m'] as $column) {
                $table->decimal($column, 18, 6)->nullable();
            }
            $table->bigInteger('number_of_stems')->nullable();
            $table->decimal('form_factor', 7, 6)->nullable();
            $table->decimal('dap_m', 24, 12)->nullable();
            $table->decimal('radius_m', 24, 12)->nullable();
            $table->decimal('basal_area_m2', 36, 12)->nullable();
            $table->decimal('commercial_volume_m3', 48, 12)->nullable();
            $table->decimal('total_volume_m3', 48, 12)->nullable();
            $table->bigInteger('diameter_class_number')->nullable();
            $table->bigInteger('height_class_number')->nullable();
            $table->foreignUuid('maturity_state_id')->nullable()->constrained('maturity_states')->restrictOnDelete();
            $table->foreignUuid('classification_rule_version_id')->constrained('classification_rule_versions')->restrictOnDelete();
            $table->string('calculation_rule_version', 40);
            $table->date('flowering_date')->nullable();
            $table->text('flowering_development')->nullable();
            $table->date('fruiting_date')->nullable();
            $table->text('fruiting_development')->nullable();
            $table->text('fauna_observations')->nullable();
            $table->text('general_observations')->nullable();
            $table->timestampsTz(6);
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'tree_id'], 'inspections_tree_tenant_fk')
                ->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->foreign(['organization_id', 'tree_id', 'inventory_tree_id'], 'inspections_inventory_same_tree_fk')
                ->references(['organization_id', 'tree_id', 'id'])->on('inventory_trees')->restrictOnDelete();
            $table->index(['organization_id', 'inspection_date'], 'inspections_date_index');
            $table->index(['organization_id', 'inventory_tree_id'], 'inspections_inventory_index');
            $table->index(['organization_id', 'created_by'], 'inspections_author_index');
        });
        DB::statement('CREATE INDEX inspections_latest_tree_index ON tree_inspections (organization_id, tree_id, inspection_date DESC, created_at DESC, id DESC)');
        DB::statement("ALTER TABLE tree_inspections ADD CONSTRAINT inspections_numeric_check CHECK (
            lock_version >= 1
            AND (cap_cm IS NULL OR (cap_cm > 0 AND cap_cm <> 'NaN'::numeric))
            AND (total_height_m IS NULL OR (total_height_m > 0 AND total_height_m <> 'NaN'::numeric))
            AND (commercial_height_m IS NULL OR (commercial_height_m >= 0 AND commercial_height_m <> 'NaN'::numeric))
            AND (total_height_m IS NULL OR commercial_height_m IS NULL OR commercial_height_m <= total_height_m)
            AND (crown_x_m IS NULL OR (crown_x_m >= 0 AND crown_x_m <> 'NaN'::numeric))
            AND (crown_y_m IS NULL OR (crown_y_m >= 0 AND crown_y_m <> 'NaN'::numeric))
            AND (number_of_stems IS NULL OR number_of_stems > 0)
            AND (form_factor IS NULL OR form_factor BETWEEN 0.45 AND 0.7)
            AND (dap_m IS NULL OR (dap_m >= 0 AND dap_m <> 'NaN'::numeric))
            AND (radius_m IS NULL OR (radius_m >= 0 AND radius_m <> 'NaN'::numeric))
            AND (basal_area_m2 IS NULL OR (basal_area_m2 >= 0 AND basal_area_m2 <> 'NaN'::numeric))
            AND (commercial_volume_m3 IS NULL OR (commercial_volume_m3 >= 0 AND commercial_volume_m3 <> 'NaN'::numeric))
            AND (total_volume_m3 IS NULL OR (total_volume_m3 >= 0 AND total_volume_m3 <> 'NaN'::numeric))
            AND (diameter_class_number IS NULL OR diameter_class_number > 0)
            AND (height_class_number IS NULL OR height_class_number > 0)
            AND calculation_rule_version ~ '^[A-Z][A-Z0-9_]{1,39}$'
        )");

        Schema::create('inspection_conditions', function (Blueprint $table): void {
            $table->uuid('organization_id');
            $table->uuid('inspection_id');
            $table->foreignUuid('condition_id')->constrained('conditions')->restrictOnDelete();
            $table->timestampTz('created_at', 6);
            $table->primary(['inspection_id', 'condition_id']);
            $table->foreign(['organization_id', 'inspection_id'], 'inspection_conditions_tenant_fk')
                ->references(['organization_id', 'id'])->on('tree_inspections')->restrictOnDelete();
            $table->index(['organization_id', 'inspection_id']);
            $table->index(['condition_id', 'inspection_id']);
        });
        Schema::create('inspection_fauna', function (Blueprint $table): void {
            $table->uuid('organization_id');
            $table->uuid('inspection_id');
            $table->foreignUuid('fauna_category_id')->constrained('fauna_categories')->restrictOnDelete();
            $table->timestampTz('created_at', 6);
            $table->primary(['inspection_id', 'fauna_category_id']);
            $table->foreign(['organization_id', 'inspection_id'], 'inspection_fauna_tenant_fk')
                ->references(['organization_id', 'id'])->on('tree_inspections')->restrictOnDelete();
            $table->index(['organization_id', 'inspection_id']);
            $table->index(['fauna_category_id', 'inspection_id']);
        });
        Schema::create('inspection_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('inspection_id');
            $table->integer('version');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at', 6);
            $table->string('event', 80);
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->unique(['inspection_id', 'version']);
            $table->foreign(['organization_id', 'inspection_id'], 'inspection_revisions_tenant_fk')
                ->references(['organization_id', 'id'])->on('tree_inspections')->restrictOnDelete();
            $table->index(['organization_id', 'inspection_id']);
        });
        DB::statement("ALTER TABLE inspection_revisions ADD CONSTRAINT inspection_revisions_values_check CHECK (
            version >= 1 AND jsonb_typeof(old_values) = 'object' AND jsonb_typeof(new_values) = 'object'
            AND event IN ('inspection.created', 'inspection.updated')
        )");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.protect_inspection_identity() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Las inspecciones se corrigen con revisión; no se eliminan.' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.id, NEW.organization_id, NEW.tree_id, NEW.created_by, NEW.created_at,
           NEW.classification_rule_version_id, NEW.calculation_rule_version)
        IS DISTINCT FROM
       ROW(OLD.id, OLD.organization_id, OLD.tree_id, OLD.created_by, OLD.created_at,
           OLD.classification_rule_version_id, OLD.calculation_rule_version) THEN
        RAISE EXCEPTION 'La identidad, autoría y reglas originales de una inspección son inmutables.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER inspection_identity_immutable BEFORE UPDATE OR DELETE ON tree_inspections
FOR EACH ROW EXECUTE FUNCTION public.protect_inspection_identity();

CREATE FUNCTION public.protect_inspection_revision() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'Las revisiones de inspecciones solo admiten nuevos registros.' USING ERRCODE = '23514';
END;
$body$;

CREATE TRIGGER inspection_revision_append_only BEFORE UPDATE OR DELETE ON inspection_revisions
FOR EACH ROW EXECUTE FUNCTION public.protect_inspection_revision();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_revisions');
        Schema::dropIfExists('inspection_fauna');
        Schema::dropIfExists('inspection_conditions');
        Schema::dropIfExists('tree_inspections');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_inspection_revision()');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_inspection_identity()');
        Schema::table('inventory_trees', function (Blueprint $table): void {
            $table->dropUnique('inventory_trees_inspection_identity_unique');
        });
    }
};
