<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table): void {
            $table->string('resource_type', 80)->nullable();
            $table->string('resource_id', 100)->nullable();
            // NULL significa «no capturado históricamente»; {} representa el estado previo de un alta.
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values')->nullable();
            $table->jsonb('context')->default('{}');
            $table->dropForeign(['actor_user_id']);
            $table->dropForeign(['organization_id']);
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->index(['resource_type', 'resource_id', 'created_at'], 'security_events_resource_index');
            $table->index(['organization_id', 'created_at', 'id'], 'security_events_timeline_index');
        });
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT security_events_audit_values_check CHECK (
            (resource_type IS NULL) = (resource_id IS NULL)
            AND (before_values IS NULL) = (after_values IS NULL)
            AND (before_values IS NULL OR (resource_type IS NOT NULL AND jsonb_typeof(before_values) = 'object' AND jsonb_typeof(after_values) = 'object'))
            AND jsonb_typeof(context) = 'object'
        )");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.protect_security_event() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'Los eventos de auditoría solo admiten nuevos registros.' USING ERRCODE = '23514';
END;
$body$;
CREATE TRIGGER security_event_append_only BEFORE UPDATE OR DELETE ON security_events
FOR EACH ROW EXECUTE FUNCTION public.protect_security_event();
CREATE TRIGGER inventory_no_physical_delete BEFORE DELETE ON inventories
FOR EACH ROW EXECUTE FUNCTION public.protect_forestry_identity();
CREATE TRIGGER inventory_participation_append_only BEFORE UPDATE OR DELETE ON inventory_trees
FOR EACH ROW EXECUTE FUNCTION public.protect_forestry_history();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS security_event_append_only ON security_events');
        DB::statement('DROP FUNCTION IF EXISTS public.protect_security_event()');
        DB::statement('DROP TRIGGER IF EXISTS inventory_no_physical_delete ON inventories');
        DB::statement('DROP TRIGGER IF EXISTS inventory_participation_append_only ON inventory_trees');
        DB::statement('ALTER TABLE security_events DROP CONSTRAINT security_events_audit_values_check');
        Schema::table('security_events', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->dropForeign(['organization_id']);
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->dropIndex('security_events_resource_index');
            $table->dropIndex('security_events_timeline_index');
            $table->dropColumn(['resource_type', 'resource_id', 'before_values', 'after_values', 'context']);
        });
    }
};
