<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('evidences', function (Blueprint $table): void {
            $table->uuid('maintenance_id')->nullable();
            $table->foreign(['organization_id', 'maintenance_id'], 'evidences_maintenance_tenant_foreign')
                ->references(['organization_id', 'id'])->on('maintenances')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE evidences DROP CONSTRAINT evidences_exactly_one_owner');
        DB::statement('ALTER TABLE evidences ADD CONSTRAINT evidences_exactly_one_owner CHECK (num_nonnulls(tree_id, tree_inspection_id, maintenance_id) = 1)');
        DB::statement('CREATE INDEX evidences_maintenance_gallery_index ON evidences (organization_id, maintenance_id, created_at DESC, id DESC) WHERE maintenance_id IS NOT NULL');
        $this->protectIdentity(true);
    }

    public function down(): void
    {
        // El rollback nunca elimina ni reasigna fotografías que ya pertenecen a un mantenimiento.
        DB::statement('LOCK TABLE evidences IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
DO $body$
BEGIN
    IF EXISTS (SELECT 1 FROM evidences WHERE maintenance_id IS NOT NULL) THEN
        RAISE EXCEPTION 'No se puede revertir: existen evidencias de mantenimiento que deben conservarse.' USING ERRCODE = '23514';
    END IF;
END;
$body$;
SQL);
        $this->protectIdentity(false);
        DB::statement('ALTER TABLE evidences DROP CONSTRAINT evidences_exactly_one_owner');
        DB::statement('ALTER TABLE evidences ADD CONSTRAINT evidences_exactly_one_owner CHECK (num_nonnulls(tree_id, tree_inspection_id) = 1)');
        DB::statement('DROP INDEX evidences_maintenance_gallery_index');
        Schema::table('evidences', function (Blueprint $table): void {
            $table->dropForeign('evidences_maintenance_tenant_foreign');
            $table->dropColumn('maintenance_id');
        });
    }

    /** Conserva todas las garantías de identidad originales e incluye el tercer propietario. */
    private function protectIdentity(bool $withMaintenance): void
    {
        $sql = <<<'SQL'
CREATE OR REPLACE FUNCTION public.protect_evidence_identity() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Las evidencias solo admiten anulación lógica.' USING ERRCODE = '23514';
    END IF;
    IF ROW(NEW.id, NEW.organization_id, NEW.tree_id, NEW.tree_inspection_id{new_maintenance},
           NEW.uploaded_by, NEW.created_at, NEW.disk, NEW.path, NEW.original_name,
           NEW.mime_type, NEW.extension, NEW.size_bytes, NEW.width, NEW.height,
           NEW.checksum_sha256, NEW.metadata)
       IS DISTINCT FROM
       ROW(OLD.id, OLD.organization_id, OLD.tree_id, OLD.tree_inspection_id{old_maintenance},
           OLD.uploaded_by, OLD.created_at, OLD.disk, OLD.path, OLD.original_name,
           OLD.mime_type, OLD.extension, OLD.size_bytes, OLD.width, OLD.height,
           OLD.checksum_sha256, OLD.metadata) THEN
        RAISE EXCEPTION 'El propietario, autoría original y procedencia del archivo son inmutables.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;
SQL;
        DB::unprepared(str_replace(
            ['{new_maintenance}', '{old_maintenance}'],
            $withMaintenance ? [', NEW.maintenance_id', ', OLD.maintenance_id'] : ['', ''],
            $sql
        ));
    }
};
