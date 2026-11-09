<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('classification_rule_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('algorithm', 32)->default('positive_ceiling_v1');
            $table->decimal('diameter_step', 18, 6);
            $table->decimal('height_step', 18, 6);
            $table->string('diameter_unit', 8)->default('cm');
            $table->string('height_unit', 8)->default('m');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('sealed_at')->nullable();
            $table->timestampsTz();
            $table->index(['active', 'sort_order']);
        });

        Schema::create('maturity_rule_bands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('rule_version_id')->constrained('classification_rule_versions')->restrictOnDelete();
            $table->foreignUuid('maturity_state_id')->constrained('maturity_states')->restrictOnDelete();
            $table->bigInteger('min_class');
            $table->bigInteger('max_class')->nullable();
            $table->timestampsTz();
            $table->unique(['rule_version_id', 'min_class']);
            $table->index('maturity_state_id');
        });

        DB::statement("ALTER TABLE classification_rule_versions ADD CONSTRAINT classification_rules_values_check CHECK (
            code ~ '^[A-Z][A-Z0-9_]{1,63}$' AND sort_order >= 0
            AND algorithm = 'positive_ceiling_v1'
            AND diameter_unit = 'cm' AND height_unit = 'm'
            AND diameter_step > 0 AND diameter_step <> 'NaN'::numeric
            AND height_step > 0 AND height_step <> 'NaN'::numeric
        )");
        DB::statement('ALTER TABLE maturity_rule_bands ADD CONSTRAINT maturity_bands_range_check CHECK (
            min_class >= 1 AND (max_class IS NULL OR max_class >= min_class)
        )');

        // El sellado hace indivisible una versión: sus bandas deben cubrir todas las clases positivas.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.sysforest_guard_classification_rule() RETURNS trigger
LANGUAGE plpgsql AS $body$
DECLARE
    band record;
    expected_min numeric := 1;
    has_terminal boolean := false;
    band_count integer := 0;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Las reglas se desactivan; no se eliminan.' USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.sealed_at IS NOT NULL THEN
            RAISE EXCEPTION 'La versión debe crear sus bandas antes de sellarse.' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF ROW(NEW.id, NEW.code, NEW.algorithm, NEW.diameter_step, NEW.height_step, NEW.diameter_unit, NEW.height_unit)
        IS DISTINCT FROM
        ROW(OLD.id, OLD.code, OLD.algorithm, OLD.diameter_step, OLD.height_step, OLD.diameter_unit, OLD.height_unit) THEN
        RAISE EXCEPTION 'Los parámetros de una versión son inmutables; crea otra versión.' USING ERRCODE = '23514';
    END IF;

    IF OLD.sealed_at IS NOT NULL AND NEW.sealed_at IS DISTINCT FROM OLD.sealed_at THEN
        RAISE EXCEPTION 'No se puede retirar ni cambiar el sellado de una versión.' USING ERRCODE = '23514';
    END IF;

    IF OLD.sealed_at IS NULL AND NEW.sealed_at IS NOT NULL THEN
        FOR band IN SELECT min_class, max_class FROM public.maturity_rule_bands
            WHERE rule_version_id = OLD.id ORDER BY min_class
        LOOP
            IF has_terminal OR band.min_class <> expected_min THEN
                RAISE EXCEPTION 'Las bandas de madurez no pueden tener huecos ni superposiciones.' USING ERRCODE = '23514';
            END IF;
            band_count := band_count + 1;
            IF band.max_class IS NULL THEN
                has_terminal := true;
            ELSE
                expected_min := band.max_class::numeric + 1;
            END IF;
        END LOOP;
        IF band_count = 0 OR NOT has_terminal THEN
            RAISE EXCEPTION 'La madurez requiere bandas desde la clase 1 y una última banda abierta.' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER classification_rule_immutable
BEFORE INSERT OR UPDATE OR DELETE ON public.classification_rule_versions
FOR EACH ROW EXECUTE FUNCTION public.sysforest_guard_classification_rule();

CREATE OR REPLACE FUNCTION public.sysforest_guard_maturity_band() RETURNS trigger
LANGUAGE plpgsql AS $body$
DECLARE
    version_sealed_at timestamptz;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Las bandas de una versión son inmutables.' USING ERRCODE = '23514';
    END IF;

    -- El bloqueo del padre serializa añadir bandas y sellar la misma versión.
    SELECT sealed_at INTO version_sealed_at FROM public.classification_rule_versions
        WHERE id = NEW.rule_version_id FOR UPDATE;
    IF version_sealed_at IS NOT NULL THEN
        RAISE EXCEPTION 'No se pueden añadir bandas a una versión sellada.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER maturity_rule_band_immutable
BEFORE INSERT OR UPDATE OR DELETE ON public.maturity_rule_bands
FOR EACH ROW EXECUTE FUNCTION public.sysforest_guard_maturity_band();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('maturity_rule_bands');
        Schema::dropIfExists('classification_rule_versions');
        DB::statement('DROP FUNCTION IF EXISTS public.sysforest_guard_maturity_band()');
        DB::statement('DROP FUNCTION IF EXISTS public.sysforest_guard_classification_rule()');
    }
};
