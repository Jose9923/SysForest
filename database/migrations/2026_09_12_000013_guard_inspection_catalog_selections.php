<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.validate_inspection_condition_selection() RETURNS trigger LANGUAGE plpgsql AS $body$
DECLARE
    condition_active boolean;
    category_active boolean;
BEGIN
    -- Bloquea desactivaciones concurrentes hasta confirmar la captura de esta relación.
    SELECT c.active, category.active INTO condition_active, category_active
    FROM public.conditions c
    JOIN public.condition_categories category ON category.id = c.condition_category_id
    WHERE c.id = NEW.condition_id
    FOR SHARE OF c, category;
    IF FOUND AND (NOT condition_active OR NOT category_active) THEN
        RAISE EXCEPTION 'La condición o su categoría ya no están disponibles para nuevas capturas.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER inspection_condition_available BEFORE INSERT ON inspection_conditions
FOR EACH ROW EXECUTE FUNCTION public.validate_inspection_condition_selection();

CREATE OR REPLACE FUNCTION public.validate_inspection_fauna_selection() RETURNS trigger LANGUAGE plpgsql AS $body$
DECLARE
    category_active boolean;
BEGIN
    SELECT active INTO category_active FROM public.fauna_categories
    WHERE id = NEW.fauna_category_id FOR SHARE;
    IF FOUND AND NOT category_active THEN
        RAISE EXCEPTION 'La categoría de fauna ya no está disponible para nuevas capturas.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER inspection_fauna_available BEFORE INSERT ON inspection_fauna
FOR EACH ROW EXECUTE FUNCTION public.validate_inspection_fauna_selection();

CREATE OR REPLACE FUNCTION public.protect_inspection_selection() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'Una selección no se reasigna ni cambia su fecha original; la corrección utiliza nuevas relaciones auditadas.' USING ERRCODE = '23514';
END;
$body$;

CREATE TRIGGER inspection_condition_identity_immutable BEFORE UPDATE ON inspection_conditions
FOR EACH ROW EXECUTE FUNCTION public.protect_inspection_selection();
CREATE TRIGGER inspection_fauna_identity_immutable BEFORE UPDATE ON inspection_fauna
FOR EACH ROW EXECUTE FUNCTION public.protect_inspection_selection();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inspection_condition_identity_immutable ON inspection_conditions');
        DB::statement('DROP TRIGGER IF EXISTS inspection_fauna_identity_immutable ON inspection_fauna');
        DB::statement('DROP TRIGGER IF EXISTS inspection_condition_available ON inspection_conditions');
        DB::statement('DROP TRIGGER IF EXISTS inspection_fauna_available ON inspection_fauna');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_inspection_selection()');
        DB::statement('DROP FUNCTION IF EXISTS public.validate_inspection_condition_selection()');
        DB::statement('DROP FUNCTION IF EXISTS public.validate_inspection_fauna_selection()');
    }
};
