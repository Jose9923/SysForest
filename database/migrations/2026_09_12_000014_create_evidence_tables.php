<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('evidences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('evidence_type_id')->constrained('evidence_types')->restrictOnDelete();
            $table->uuid('tree_id')->nullable();
            $table->uuid('tree_inspection_id')->nullable();
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->bigInteger('size_bytes');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->text('caption')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('captured_at', 6)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->integer('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->softDeletesTz('deleted_at', 6);
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('deletion_notes')->nullable();
            $table->unique(['organization_id', 'id']);
            // Una anulación conserva la referencia física: su ruta tampoco se reutiliza.
            $table->unique(['disk', 'path']);
            $table->foreign(['organization_id', 'tree_id'], 'evidences_tree_tenant_fk')
                ->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->foreign(['organization_id', 'tree_inspection_id'], 'evidences_inspection_tenant_fk')
                ->references(['organization_id', 'id'])->on('tree_inspections')->restrictOnDelete();
            $table->index(['organization_id', 'evidence_type_id', 'created_at'], 'evidences_type_date_index');
            $table->index(['organization_id', 'deleted_at'], 'evidences_lifecycle_index');
        });
        DB::statement('ALTER TABLE evidences ADD CONSTRAINT evidences_exactly_one_owner CHECK (num_nonnulls(tree_id, tree_inspection_id) = 1)');
        DB::statement("ALTER TABLE evidences ADD CONSTRAINT evidences_values_check CHECK (
            lock_version >= 1 AND size_bytes > 0
            AND (width IS NULL OR width > 0) AND (height IS NULL OR height > 0)
            AND (checksum_sha256 IS NULL OR checksum_sha256 ~ '^[0-9a-f]{64}$')
            AND (metadata IS NULL OR jsonb_typeof(metadata) = 'object')
            AND ((deleted_at IS NULL AND deleted_by IS NULL) OR (deleted_at IS NOT NULL AND deleted_by IS NOT NULL))
            AND length(disk) > 0 AND length(path) > 0 AND length(original_name) > 0
            AND length(mime_type) > 0 AND length(extension) > 0
        )");
        DB::statement('CREATE INDEX evidences_tree_gallery_index ON evidences (organization_id, tree_id, created_at DESC, id DESC) WHERE tree_id IS NOT NULL');
        DB::statement('CREATE INDEX evidences_inspection_gallery_index ON evidences (organization_id, tree_inspection_id, created_at DESC, id DESC) WHERE tree_inspection_id IS NOT NULL');

        Schema::create('evidence_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('evidence_id');
            $table->integer('version');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at', 6);
            $table->string('event', 80);
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->unique(['evidence_id', 'version']);
            $table->foreign(['organization_id', 'evidence_id'], 'evidence_revisions_tenant_fk')
                ->references(['organization_id', 'id'])->on('evidences')->restrictOnDelete();
            $table->index(['organization_id', 'evidence_id']);
        });
        DB::statement("ALTER TABLE evidence_revisions ADD CONSTRAINT evidence_revisions_values_check CHECK (
            version >= 1 AND jsonb_typeof(old_values) = 'object' AND jsonb_typeof(new_values) = 'object'
            AND event IN ('evidence.uploaded', 'evidence.updated', 'evidence.voided', 'evidence.restored')
        )");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.protect_evidence_identity() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Las evidencias se anulan sin borrar su registro ni su archivo.' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.id, NEW.organization_id, NEW.tree_id, NEW.tree_inspection_id,
           NEW.uploaded_by, NEW.created_at, NEW.disk, NEW.path, NEW.original_name,
           NEW.mime_type, NEW.extension, NEW.size_bytes, NEW.width, NEW.height,
           NEW.checksum_sha256, NEW.metadata)
       IS DISTINCT FROM
       ROW(OLD.id, OLD.organization_id, OLD.tree_id, OLD.tree_inspection_id,
           OLD.uploaded_by, OLD.created_at, OLD.disk, OLD.path, OLD.original_name,
           OLD.mime_type, OLD.extension, OLD.size_bytes, OLD.width, OLD.height,
           OLD.checksum_sha256, OLD.metadata) THEN
        RAISE EXCEPTION 'El propietario, autoría original y procedencia del archivo son inmutables.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER evidence_identity_immutable BEFORE UPDATE OR DELETE ON evidences
FOR EACH ROW EXECUTE FUNCTION public.protect_evidence_identity();

CREATE OR REPLACE FUNCTION public.validate_evidence_type_selection() RETURNS trigger LANGUAGE plpgsql AS $body$
DECLARE
    type_active boolean;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.evidence_type_id IS NOT DISTINCT FROM OLD.evidence_type_id THEN
        RETURN NEW;
    END IF;
    SELECT active INTO type_active FROM public.evidence_types
    WHERE id = NEW.evidence_type_id FOR SHARE;
    IF FOUND AND NOT type_active THEN
        RAISE EXCEPTION 'El tipo de evidencia no está disponible para nuevas selecciones.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;

CREATE TRIGGER evidence_type_available BEFORE INSERT OR UPDATE OF evidence_type_id ON evidences
FOR EACH ROW EXECUTE FUNCTION public.validate_evidence_type_selection();

CREATE OR REPLACE FUNCTION public.protect_evidence_revision() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'Las revisiones de evidencias solo admiten nuevos registros.' USING ERRCODE = '23514';
END;
$body$;

CREATE TRIGGER evidence_revision_append_only BEFORE UPDATE OR DELETE ON evidence_revisions
FOR EACH ROW EXECUTE FUNCTION public.protect_evidence_revision();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_revisions');
        Schema::dropIfExists('evidences');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_evidence_revision()');
        DB::statement('DROP FUNCTION IF EXISTS public.validate_evidence_type_selection()');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_evidence_identity()');
    }
};
