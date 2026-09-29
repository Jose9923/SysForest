<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX import_batches_completed_source_unique ON import_batches (organization_id,file_sha256) WHERE status='completed'");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.validate_completed_import_batch() RETURNS trigger LANGUAGE plpgsql AS $body$
DECLARE
    rows_total integer;
    rows_valid integer;
    rows_invalid integer;
    rows_imported integer;
BEGIN
    SELECT count(*), count(*) FILTER (WHERE status IN ('valid','imported')),
        count(*) FILTER (WHERE status='invalid'), count(*) FILTER (WHERE status='imported')
        INTO rows_total,rows_valid,rows_invalid,rows_imported FROM public.import_rows
        WHERE organization_id=NEW.organization_id AND import_batch_id=NEW.id;
    IF rows_total <> NEW.total_rows OR rows_valid <> NEW.valid_rows OR rows_invalid <> NEW.invalid_rows
        OR rows_imported <> NEW.imported_rows THEN
        RAISE EXCEPTION 'Los contadores del lote deben corresponder a todas sus filas.' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$body$;
CREATE TRIGGER import_batch_result_counts BEFORE UPDATE ON import_batches
FOR EACH ROW EXECUTE FUNCTION public.validate_completed_import_batch();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS import_batch_result_counts ON import_batches');
        DB::statement('DROP FUNCTION IF EXISTS public.validate_completed_import_batch()');
        DB::statement('DROP INDEX IF EXISTS import_batches_completed_source_unique');
    }
};
