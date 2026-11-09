<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('tree_id');
            $table->foreignUuid('maintenance_type_id')->constrained('maintenance_types')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('status', 30)->default('pending');
            $table->string('priority', 20)->default('normal');
            $table->date('scheduled_date')->nullable();
            $table->date('performed_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->text('result')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->integer('lock_version')->default(1);
            $table->timestampsTz(6);
            $table->softDeletesTz('deleted_at', 6);
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('deletion_notes')->nullable();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'code']);
            $table->foreign(['organization_id', 'tree_id'], 'maintenances_tree_tenant_fk')
                ->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->foreign(['organization_id', 'assigned_to'], 'maintenances_assignee_tenant_fk')
                ->references(['organization_id', 'user_id'])->on('organization_memberships')->restrictOnDelete();
            $table->index(['organization_id', 'tree_id', 'created_at']);
            $table->index(['organization_id', 'status', 'scheduled_date']);
            $table->index(['organization_id', 'assigned_to']);
            $table->index(['organization_id', 'maintenance_type_id']);
            $table->index(['organization_id', 'deleted_at']);
        });
        DB::statement("ALTER TABLE maintenances ADD CONSTRAINT maintenances_values_check CHECK (
            lock_version >= 1 AND code ~ '^MNT-[0-9]{6,}$'
            AND status IN ('pending','scheduled','assigned','in_progress','executed','verified','cancelled')
            AND priority IN ('low','normal','high','urgent')
            AND (status <> 'scheduled' OR scheduled_date IS NOT NULL)
            AND (status NOT IN ('assigned','in_progress','executed','verified') OR assigned_to IS NOT NULL)
            AND (status NOT IN ('executed','verified') OR (performed_date IS NOT NULL AND length(btrim(coalesce(result, ''))) > 0))
            AND (status IN ('executed','verified') OR performed_date IS NULL)
            AND ((deleted_at IS NULL AND deleted_by IS NULL) OR
                 (deleted_at IS NOT NULL AND deleted_by IS NOT NULL AND length(btrim(coalesce(deletion_notes, ''))) > 0))
        )");

        Schema::create('maintenance_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('maintenance_id');
            $table->integer('version');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at', 6);
            $table->string('event', 80);
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->unique(['maintenance_id', 'version']);
            $table->foreign(['organization_id', 'maintenance_id'], 'maintenance_revisions_tenant_fk')
                ->references(['organization_id', 'id'])->on('maintenances')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_revisions ADD CONSTRAINT maintenance_revisions_values_check CHECK (
            version >= 1 AND jsonb_typeof(old_values) = 'object' AND jsonb_typeof(new_values) = 'object'
            AND event IN ('maintenance.created','maintenance.updated','maintenance.status_changed','maintenance.voided','maintenance.restored')
        )");
        Schema::create('maintenance_status_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('maintenance_id');
            $table->integer('version');
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at', 6);
            $table->unique(['maintenance_id', 'version']);
            $table->foreign(['organization_id', 'maintenance_id'], 'maintenance_status_histories_tenant_fk')
                ->references(['organization_id', 'id'])->on('maintenances')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_status_histories ADD CONSTRAINT maintenance_status_histories_values_check CHECK (
            version >= 1 AND new_status IN ('pending','scheduled','assigned','in_progress','executed','verified','cancelled')
            AND (old_status IS NULL OR old_status IN ('pending','scheduled','assigned','in_progress','executed','verified','cancelled'))
            AND (old_status IS DISTINCT FROM new_status)
            AND (new_status <> 'cancelled' OR length(btrim(coalesce(reason, ''))) > 0)
        )");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.protect_maintenance_identity_and_workflow() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Los mantenimientos se anulan sin eliminar su historia.' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'pending' THEN
            RAISE EXCEPTION 'Una orden manual comienza pendiente.' USING ERRCODE = '23514';
        END IF;
    ELSE
        IF ROW(NEW.id,NEW.organization_id,NEW.tree_id,NEW.code,NEW.created_by,NEW.created_at)
           IS DISTINCT FROM ROW(OLD.id,OLD.organization_id,OLD.tree_id,OLD.code,OLD.created_by,OLD.created_at) THEN
            RAISE EXCEPTION 'La identidad y autoría original del mantenimiento son inmutables.' USING ERRCODE = '23514';
        END IF;
        IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
            (OLD.status = 'pending' AND NEW.status IN ('scheduled','assigned','cancelled')) OR
            (OLD.status = 'scheduled' AND NEW.status IN ('assigned','cancelled')) OR
            (OLD.status = 'assigned' AND NEW.status IN ('scheduled','in_progress','cancelled')) OR
            (OLD.status = 'in_progress' AND NEW.status IN ('executed','cancelled')) OR
            (OLD.status = 'executed' AND NEW.status = 'verified')
        ) THEN
            RAISE EXCEPTION 'La transición del mantenimiento no está permitida.' USING ERRCODE = '23514';
        END IF;
        IF OLD.status IN ('executed','verified','cancelled') AND
           ROW(NEW.maintenance_type_id,NEW.priority,NEW.scheduled_date,NEW.performed_date,NEW.assigned_to,NEW.notes,NEW.result)
           IS DISTINCT FROM ROW(OLD.maintenance_type_id,OLD.priority,OLD.scheduled_date,OLD.performed_date,OLD.assigned_to,OLD.notes,OLD.result) THEN
            RAISE EXCEPTION 'Una orden finalizada conserva sus datos operativos.' USING ERRCODE = '23514';
        END IF;
    END IF;
    IF NEW.performed_date IS NOT NULL AND NEW.performed_date > (CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date THEN
        RAISE EXCEPTION 'La ejecución no puede tener una fecha futura.' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$body$;
CREATE TRIGGER maintenance_identity_and_workflow BEFORE INSERT OR UPDATE OR DELETE ON maintenances
FOR EACH ROW EXECUTE FUNCTION public.protect_maintenance_identity_and_workflow();

CREATE OR REPLACE FUNCTION public.validate_maintenance_references() RETURNS trigger LANGUAGE plpgsql AS $body$
DECLARE
    available boolean;
    check_assignee boolean;
BEGIN
    IF TG_OP = 'INSERT' OR NEW.maintenance_type_id IS DISTINCT FROM OLD.maintenance_type_id THEN
        SELECT active INTO available FROM public.maintenance_types WHERE id = NEW.maintenance_type_id FOR SHARE;
        IF FOUND AND NOT available THEN
            RAISE EXCEPTION 'El tipo de mantenimiento no está disponible.' USING ERRCODE = '23514';
        END IF;
    END IF;
    check_assignee := TG_OP = 'INSERT';
    IF TG_OP = 'UPDATE' THEN
        check_assignee := NEW.assigned_to IS DISTINCT FROM OLD.assigned_to OR
            (NEW.status IS DISTINCT FROM OLD.status AND NEW.status IN ('assigned','in_progress','executed'));
    END IF;
    IF NEW.assigned_to IS NOT NULL AND check_assignee THEN
        -- NOWAIT evita esperar por otro actor que ya esté esperando el lock institucional.
        PERFORM u.id FROM public.users u
        JOIN public.organization_memberships m ON m.user_id = u.id AND m.organization_id = NEW.organization_id
        JOIN public.organization_role_assignments a ON a.membership_id = m.id AND a.organization_id = m.organization_id
        JOIN public.roles r ON r.id = a.role_id
        WHERE u.id = NEW.assigned_to AND u.active AND u.email_verified_at IS NOT NULL
          AND m.active AND m.left_at IS NULL AND a.active AND a.revoked_at IS NULL
          AND r.scope = 'organization' AND r.name IN ('OFFICIAL','ORGANIZATION_ADMIN')
        FOR SHARE OF u,m,a,r NOWAIT;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'El responsable no tiene una asignación institucional vigente.' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$body$;
CREATE TRIGGER maintenance_references_available BEFORE INSERT OR UPDATE ON maintenances
FOR EACH ROW EXECUTE FUNCTION public.validate_maintenance_references();

CREATE OR REPLACE FUNCTION public.protect_maintenance_history() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'La historia del mantenimiento solo admite nuevos registros.' USING ERRCODE = '23514';
END;
$body$;
CREATE TRIGGER maintenance_revision_append_only BEFORE UPDATE OR DELETE ON maintenance_revisions
FOR EACH ROW EXECUTE FUNCTION public.protect_maintenance_history();
CREATE TRIGGER maintenance_status_append_only BEFORE UPDATE OR DELETE ON maintenance_status_histories
FOR EACH ROW EXECUTE FUNCTION public.protect_maintenance_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_status_histories');
        Schema::dropIfExists('maintenance_revisions');
        Schema::dropIfExists('maintenances');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_maintenance_history()');
        DB::statement('DROP FUNCTION IF EXISTS public.validate_maintenance_references()');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_maintenance_identity_and_workflow()');
    }
};
