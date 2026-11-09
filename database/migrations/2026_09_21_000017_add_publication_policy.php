<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        foreach (['organizations', 'inventories', 'trees', 'tree_inspections', 'evidences'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('is_public')->default(false);
                $table->integer('publication_version')->default(1);
            });
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_publication_version_check CHECK (publication_version >= 1)");
        }
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('public_code', 40)->nullable()->unique();
        });
        Schema::table('evidences', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable()->unique();
            $table->text('public_caption')->nullable();
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_public_identity_check CHECK (
            (NOT is_public OR public_code IS NOT NULL)
            AND (public_code IS NULL OR public_code ~ '^[A-Z0-9]+(-[A-Z0-9]+)*$')
        )");
        DB::statement("ALTER TABLE evidences ADD CONSTRAINT evidences_public_identity_check CHECK (
            (NOT is_public OR public_id IS NOT NULL)
            AND (public_id IS NULL OR public_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$')
            AND (public_caption IS NULL OR char_length(public_caption) <= 2000)
        )");

        Schema::create('publication_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('organization_subject_id')->nullable();
            $table->uuid('inventory_id')->nullable();
            $table->uuid('tree_id')->nullable();
            $table->uuid('tree_inspection_id')->nullable();
            $table->uuid('evidence_id')->nullable();
            $table->integer('version');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at', 6);
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('organization_subject_id')->references('id')->on('organizations')->restrictOnDelete();
            foreach (['inventory_id' => 'inventories', 'tree_id' => 'trees',
                'tree_inspection_id' => 'tree_inspections', 'evidence_id' => 'evidences'] as $column => $target) {
                $table->foreign(['organization_id', $column], 'publication_'.$column.'_tenant_fk')
                    ->references(['organization_id', 'id'])->on($target)->restrictOnDelete();
            }
            foreach (['organization_subject_id', 'inventory_id', 'tree_id', 'tree_inspection_id', 'evidence_id'] as $column) {
                $table->unique([$column, 'version'], 'publication_'.$column.'_version_unique');
            }
            $table->index(['organization_id', 'changed_at']);
        });
        DB::statement("ALTER TABLE publication_revisions ADD CONSTRAINT publication_revisions_values_check CHECK (
            num_nonnulls(organization_subject_id, inventory_id, tree_id, tree_inspection_id, evidence_id) = 1
            AND (organization_subject_id IS NULL OR organization_subject_id = organization_id)
            AND version >= 2 AND jsonb_typeof(old_values) = 'object' AND jsonb_typeof(new_values) = 'object'
        )");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.protect_public_identity() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_TABLE_NAME = 'organizations' THEN
        IF OLD.public_code IS NOT NULL AND NEW.public_code IS DISTINCT FROM OLD.public_code THEN
            RAISE EXCEPTION 'El código público reservado de la organización es inmutable.' USING ERRCODE = '23514';
        END IF;
    ELSIF OLD.public_id IS NOT NULL AND NEW.public_id IS DISTINCT FROM OLD.public_id THEN
        RAISE EXCEPTION 'El identificador público reservado de la evidencia es inmutable.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;
CREATE TRIGGER organization_public_identity_immutable BEFORE UPDATE ON organizations
FOR EACH ROW EXECUTE FUNCTION public.protect_public_identity();
CREATE TRIGGER evidence_public_identity_immutable BEFORE UPDATE ON evidences
FOR EACH ROW EXECUTE FUNCTION public.protect_public_identity();

CREATE OR REPLACE FUNCTION public.protect_publication_revision() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'El historial de publicación solo admite nuevas revisiones.' USING ERRCODE = '23514';
END;
$body$;
CREATE TRIGGER publication_revision_append_only BEFORE UPDATE OR DELETE ON publication_revisions
FOR EACH ROW EXECUTE FUNCTION public.protect_publication_revision();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_revisions');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_publication_revision()');
        DB::statement('DROP TRIGGER IF EXISTS organization_public_identity_immutable ON organizations');
        DB::statement('DROP TRIGGER IF EXISTS evidence_public_identity_immutable ON evidences');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_public_identity()');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT organizations_public_identity_check');
        DB::statement('ALTER TABLE evidences DROP CONSTRAINT evidences_public_identity_check');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('public_code'));
        Schema::table('evidences', fn (Blueprint $table) => $table->dropColumn(['public_id', 'public_caption']));
        foreach (['organizations', 'inventories', 'trees', 'tree_inspections', 'evidences'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT {$tableName}_publication_version_check");
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(['is_public', 'publication_version']));
        }
    }
};
