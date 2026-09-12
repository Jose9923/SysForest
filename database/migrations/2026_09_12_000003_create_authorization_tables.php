<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 64)->unique();
            $table->string('scope', 20);
            $table->timestampsTz();
            $table->unique(['id', 'scope']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('description');
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('national_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('role_id');
            $table->string('scope', 20)->default('national');
            $table->boolean('active')->default(true);
            $table->timestampTz('assigned_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $table->unique(['user_id', 'role_id']);
            $table->index(['user_id', 'active']);
        });

        Schema::create('department_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
            $table->uuid('role_id');
            $table->string('scope', 20)->default('department');
            $table->boolean('active')->default(true);
            $table->timestampTz('assigned_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $table->unique(['user_id', 'department_id', 'role_id'], 'department_assignments_user_scope_role_unique');
            $table->index(['user_id', 'active']);
            $table->index(['department_id', 'active']);
        });

        Schema::create('organization_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('membership_id')->unique();
            $table->uuid('role_id');
            $table->string('scope', 20)->default('organization');
            $table->boolean('active')->default(true);
            $table->timestampTz('assigned_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['organization_id', 'membership_id'], 'organization_assignments_membership_tenant_fk')
                ->references(['organization_id', 'id'])->on('organization_memberships')->restrictOnDelete();
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $table->index(['organization_id', 'active']);
        });

        // La base vincula el ámbito al rol; una etiqueta de ámbito manipulada no eleva privilegios.
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_name_scope_check CHECK (
            (name = 'SUPER_ADMIN' AND scope = 'national')
            OR (name = 'DEPARTMENT_ADMIN' AND scope = 'department')
            OR (name IN ('ORGANIZATION_ADMIN', 'OFFICIAL') AND scope = 'organization')
        )");

        foreach (['national', 'department', 'organization'] as $scope) {
            $table = $scope.'_role_assignments';
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$scope}_assignments_scope_check CHECK (scope = '{$scope}')");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$scope}_assignments_dates_check CHECK (
                (NOT active OR revoked_at IS NULL)
                AND (revoked_at IS NULL OR revoked_at >= assigned_at)
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_role_assignments');
        Schema::dropIfExists('department_role_assignments');
        Schema::dropIfExists('national_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
