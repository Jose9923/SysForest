<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('risk_methodologies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 150);
            $table->jsonb('configuration');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['organization_id', 'version']);
            $table->unique(['organization_id', 'id']);
        });
        Schema::create('risk_settings', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->primary()->constrained()->restrictOnDelete();
            $table->uuid('methodology_id');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreign(['organization_id', 'methodology_id'])->references(['organization_id', 'id'])->on('risk_methodologies')->restrictOnDelete();
        });
        Schema::create('risk_condition_rules', function (Blueprint $table): void {
            $table->uuid('methodology_id');
            $table->foreignUuid('condition_id')->constrained('conditions')->restrictOnDelete();
            $table->unsignedSmallInteger('weight');
            $table->unsignedSmallInteger('minimum_priority')->default(0);
            $table->string('component', 30);
            $table->foreign('methodology_id')->references('id')->on('risk_methodologies')->restrictOnDelete();
            $table->primary(['methodology_id', 'condition_id']);
        });
        Schema::create('risk_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('inspection_id');
            $table->unsignedInteger('inspection_version');
            $table->uuid('methodology_id');
            $table->jsonb('result');
            $table->unsignedSmallInteger('priority')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at', 6)->nullable();
            $table->text('observations')->nullable();
            $table->string('origin', 20);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['inspection_id', 'inspection_version']);
            $table->index(['organization_id', 'priority']);
            $table->foreign(['organization_id', 'inspection_id'])->references(['organization_id', 'id'])->on('tree_inspections')->restrictOnDelete();
            $table->foreign(['organization_id', 'methodology_id'])->references(['organization_id', 'id'])->on('risk_methodologies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE risk_methodologies ADD CHECK (version > 0)");
        DB::statement("ALTER TABLE risk_settings ADD CHECK (lock_version > 0)");
        DB::statement("ALTER TABLE risk_condition_rules ADD CHECK (weight BETWEEN 0 AND 4 AND minimum_priority BETWEEN 0 AND 4)");
        DB::statement("ALTER TABLE risk_evaluations ADD CHECK (inspection_version > 0 AND origin IN ('web','import','historical','mobile'))");
        DB::statement("ALTER TABLE risk_evaluations ADD CHECK ((priority IS NULL AND confirmed_by IS NULL AND confirmed_at IS NULL) OR (priority BETWEEN 1 AND 4 AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND result->>'status' = 'valid'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sysforest_risk_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'El historial de riesgo es inmutable; cree una nueva versión';
END;
$$;
CREATE TRIGGER risk_methodology_immutable BEFORE UPDATE OR DELETE ON risk_methodologies FOR EACH ROW EXECUTE FUNCTION sysforest_risk_immutable();
CREATE TRIGGER risk_rules_immutable BEFORE UPDATE OR DELETE ON risk_condition_rules FOR EACH ROW EXECUTE FUNCTION sysforest_risk_immutable();
CREATE TRIGGER risk_evaluation_immutable BEFORE UPDATE OR DELETE ON risk_evaluations FOR EACH ROW EXECUTE FUNCTION sysforest_risk_immutable();
SQL);
        foreach (['risk.view' => ['SUPER_ADMIN', 'DEPARTMENT_ADMIN', 'ORGANIZATION_ADMIN', 'OFFICIAL'],
            'risk.manage' => ['SUPER_ADMIN', 'ORGANIZATION_ADMIN']] as $code => $names) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code,
                'description' => $code === 'risk.manage' ? 'Parametrizar versiones de riesgo de la institución autorizada.' : 'Consultar gestión de riesgo privada.',
                'created_at' => now(), 'updated_at' => now()]);
            $permission = DB::table('permissions')->where('code', $code)->value('id');
            foreach (DB::table('roles')->whereIn('name', $names)->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_evaluations');
        Schema::dropIfExists('risk_condition_rules');
        Schema::dropIfExists('risk_settings');
        Schema::dropIfExists('risk_methodologies');
        DB::statement('DROP FUNCTION IF EXISTS sysforest_risk_immutable()');
    }
};
