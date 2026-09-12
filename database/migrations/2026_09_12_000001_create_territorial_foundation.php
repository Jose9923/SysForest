<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 12)->unique();
            $table->string('name', 150);
            $table->char('iso_alpha_2', 2)->nullable()->unique();
            $table->char('iso_alpha_3', 3)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->constrained()->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 150);
            $table->geometry('geometry', 'multipolygon', 4326)->nullable();
            $table->spatialIndex('geometry');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['country_id', 'code']);
        });

        Schema::create('municipalities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 150);
            $table->geometry('geometry', 'multipolygon', 4326)->nullable();
            $table->spatialIndex('geometry');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['department_id', 'code']);
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Incluso los organismos nacionales tienen una sede municipal obligatoria.
            $table->foreignUuid('municipality_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('name', 200);
            $table->string('short_name', 80)->nullable();
            $table->string('code', 40)->unique();
            $table->string('tree_code_prefix', 60)->unique();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->jsonb('settings')->default('{}');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['municipality_id', 'active']);
        });

        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Se conserva el BIGINT de users de Fase 2, sin reconstruir cuentas existentes.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'organization_id']);
            // Permite futuras FK (organization_id, membership_id) sin asociaciones cruzadas.
            $table->unique(['organization_id', 'id'], 'memberships_tenant_id_unique');
            $table->index(['organization_id', 'active']);
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_type_check CHECK (type IN ('school','municipality','department_entity','national_entity','other'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_settings_object_check CHECK (jsonb_typeof(settings) = 'object')");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_code_check CHECK (code ~ '^[A-Z0-9]+(-[A-Z0-9]+)*$' AND tree_code_prefix ~ '^[A-Z0-9]+(-[A-Z0-9]+)*$')");
        DB::statement('ALTER TABLE organization_memberships ADD CONSTRAINT memberships_dates_check CHECK ((NOT active OR left_at IS NULL) AND (joined_at IS NULL OR left_at IS NULL OR left_at >= joined_at))');
        DB::statement('ALTER TABLE departments ADD CONSTRAINT departments_geometry_valid CHECK (geometry IS NULL OR ST_IsValid(geometry))');
        DB::statement('ALTER TABLE municipalities ADD CONSTRAINT municipalities_geometry_valid CHECK (geometry IS NULL OR ST_IsValid(geometry))');
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('countries');
        // PostGIS pertenece a la infraestructura y no se elimina al revertir el dominio.
    }
};
