<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // Conserva la precisión recibida del GPS para comparar capturas sin falsos cambios.
        DB::statement('ALTER TABLE trees ALTER COLUMN captured_at TYPE timestamp(6) with time zone');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.protect_inventory_code() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF NEW.code IS DISTINCT FROM OLD.code THEN
        RAISE EXCEPTION 'El código del inventario es inmutable.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER inventories_code_immutable BEFORE UPDATE OF code ON inventories
FOR EACH ROW EXECUTE FUNCTION public.protect_inventory_code();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventories_code_immutable ON inventories');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_inventory_code()');
        DB::statement('ALTER TABLE trees ALTER COLUMN captured_at TYPE timestamp(0) with time zone');
    }
};
