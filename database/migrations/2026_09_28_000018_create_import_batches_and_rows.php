<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('inventory_id');
            $table->string('code', 40);
            $table->string('schema_version', 60);
            $table->string('file_name', 255);
            $table->string('file_sha256', 64);
            $table->integer('file_size');
            $table->string('status', 20);
            $table->integer('total_rows');
            $table->integer('valid_rows');
            $table->integer('invalid_rows');
            $table->integer('imported_rows')->default(0);
            $table->integer('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestampTz('expires_at', 6);
            $table->text('notice')->nullable();
            $table->timestampsTz(6);
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'created_at']);
            $table->foreign(['organization_id', 'inventory_id'], 'import_batches_inventory_tenant_fk')
                ->references(['organization_id', 'id'])->on('inventories')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_values_check CHECK (
            status IN ('ready','invalid','completed','cancelled') AND lock_version >= 1
            AND file_sha256 ~ '^[a-f0-9]{64}$' AND file_size > 0
            AND total_rows > 0 AND valid_rows >= 0 AND invalid_rows >= 0
            AND total_rows = valid_rows + invalid_rows
            AND (status NOT IN ('ready','completed') OR invalid_rows = 0)
            AND (status <> 'invalid' OR invalid_rows > 0)
            AND ((status = 'completed' AND imported_rows = total_rows AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL)
                OR (status <> 'completed' AND imported_rows = 0 AND confirmed_by IS NULL AND confirmed_at IS NULL))
            AND ((status = 'cancelled' AND cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL)
                OR (status <> 'cancelled' AND cancelled_by IS NULL AND cancelled_at IS NULL))
        )");
        Schema::create('import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('import_batch_id');
            $table->integer('row_number');
            $table->string('status', 20);
            $table->jsonb('raw_values');
            $table->jsonb('reader_errors');
            $table->jsonb('normalized_values');
            $table->jsonb('errors');
            $table->uuid('tree_id')->nullable();
            $table->string('tree_code', 160)->nullable();
            $table->uuid('inspection_id')->nullable();
            $table->timestampsTz(6);
            $table->unique(['import_batch_id', 'row_number']);
            $table->unique('inspection_id');
            $table->index(['organization_id', 'import_batch_id', 'status']);
            $table->foreign(['organization_id', 'import_batch_id'], 'import_rows_batch_tenant_fk')
                ->references(['organization_id', 'id'])->on('import_batches')->restrictOnDelete();
            $table->foreign(['organization_id', 'tree_id'], 'import_rows_tree_tenant_fk')
                ->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->foreign(['organization_id', 'inspection_id'], 'import_rows_inspection_tenant_fk')
                ->references(['organization_id', 'id'])->on('tree_inspections')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_values_check CHECK (
            row_number >= 2 AND status IN ('valid','invalid','imported')
            AND jsonb_typeof(raw_values) = 'object' AND jsonb_typeof(normalized_values) = 'object'
            AND ((status = 'imported' AND tree_id IS NOT NULL AND inspection_id IS NOT NULL AND tree_code IS NOT NULL)
                OR (status <> 'imported' AND tree_id IS NULL AND inspection_id IS NULL))
        )");
        // El archivo no es autoridad para tenant/autores. También la base conserva los originales de auditoría.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.protect_import_batch() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'El lote se cancela sin eliminar la trazabilidad.' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.id,NEW.organization_id,NEW.inventory_id,NEW.code,NEW.schema_version,NEW.file_name,NEW.file_sha256,
        NEW.file_size,NEW.total_rows,NEW.created_by,NEW.created_at,NEW.expires_at)
        IS DISTINCT FROM ROW(OLD.id,OLD.organization_id,OLD.inventory_id,OLD.code,OLD.schema_version,OLD.file_name,OLD.file_sha256,
        OLD.file_size,OLD.total_rows,OLD.created_by,OLD.created_at,OLD.expires_at) THEN
        RAISE EXCEPTION 'La identidad y fuente del lote son inmutables.' USING ERRCODE = '23514';
    END IF;
    IF OLD.status IN ('completed','cancelled') THEN
        RAISE EXCEPTION 'Un lote finalizado conserva su resultado.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;
CREATE TRIGGER import_batch_identity BEFORE UPDATE OR DELETE ON import_batches
FOR EACH ROW EXECUTE FUNCTION public.protect_import_batch();

CREATE OR REPLACE FUNCTION public.protect_import_row() RETURNS trigger LANGUAGE plpgsql AS $body$
DECLARE
    batch_status text;
    inspection_tree uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Las filas conservan la trazabilidad de la importación.' USING ERRCODE = '23514';
    END IF;
    SELECT status INTO batch_status FROM public.import_batches
        WHERE organization_id = NEW.organization_id AND id = NEW.import_batch_id;
    IF batch_status IN ('completed','cancelled') THEN
        RAISE EXCEPTION 'Las filas de un lote finalizado son inmutables.' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND ROW(NEW.id,NEW.organization_id,NEW.import_batch_id,NEW.row_number,NEW.raw_values,NEW.reader_errors,NEW.created_at)
        IS DISTINCT FROM ROW(OLD.id,OLD.organization_id,OLD.import_batch_id,OLD.row_number,OLD.raw_values,OLD.reader_errors,OLD.created_at) THEN
        RAISE EXCEPTION 'La fila original de importación es inmutable.' USING ERRCODE = '23514';
    END IF;
    IF NEW.inspection_id IS NOT NULL THEN
        SELECT tree_id INTO inspection_tree FROM public.tree_inspections
            WHERE organization_id = NEW.organization_id AND id = NEW.inspection_id;
        IF FOUND AND inspection_tree IS DISTINCT FROM NEW.tree_id THEN
            RAISE EXCEPTION 'La inspección importada debe corresponder al mismo individuo.' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;
CREATE TRIGGER import_row_identity BEFORE INSERT OR UPDATE OR DELETE ON import_rows
FOR EACH ROW EXECUTE FUNCTION public.protect_import_row();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_import_row()');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_import_batch()');
    }
};
