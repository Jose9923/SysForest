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
        Schema::create('carbon_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('species_id')->constrained('species')->restrictOnDelete();
            $table->boolean('applicable');
            $table->decimal('wood_density', 8, 6)->nullable();
            $table->decimal('max_diameter_cm', 10, 3)->nullable();
            $table->decimal('max_height_m', 10, 3)->nullable();
            $table->text('reference');
            $table->text('justification');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'species_id', 'id']);
        });
        Schema::create('carbon_estimates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('organization_id');
            $table->uuid('inspection_id');
            $table->unsignedInteger('inspection_version');
            $table->foreignUuid('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->string('input_key', 64);
            $table->string('method_version', 80);
            $table->string('status', 60);
            $table->jsonb('inputs');
            $table->jsonb('method');
            $table->decimal('biomass_kg', 24, 6)->nullable();
            $table->decimal('carbon_kg', 24, 6)->nullable();
            $table->decimal('co2_kg', 24, 6)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['inspection_id', 'inspection_version', 'input_key']);
            $table->index(['organization_id', 'inspection_id', 'inspection_version', 'id']);
            $table->foreign(['organization_id', 'inspection_id'])->references(['organization_id', 'id'])->on('tree_inspections')->restrictOnDelete();
            $table->foreign(['organization_id', 'profile_id'])->references(['organization_id', 'id'])->on('carbon_profiles')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE carbon_profiles ADD CHECK (NOT applicable OR (wood_density > 0 AND wood_density <= 2 AND max_diameter_cm >= 5 AND max_height_m > 0))");
        DB::statement("ALTER TABLE carbon_estimates ADD CHECK (inspection_version > 0 AND ((status = 'estimated' AND biomass_kg > 0 AND carbon_kg > 0 AND co2_kg > 0) OR (status <> 'estimated' AND biomass_kg IS NULL AND carbon_kg IS NULL AND co2_kg IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION sysforest_carbon_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'El historial de carbono es inmutable; agregue una nueva estimación o referencia';
END;
$$;
CREATE TRIGGER carbon_profile_immutable BEFORE UPDATE OR DELETE ON carbon_profiles FOR EACH ROW EXECUTE FUNCTION sysforest_carbon_immutable();
CREATE TRIGGER carbon_estimate_immutable BEFORE UPDATE OR DELETE ON carbon_estimates FOR EACH ROW EXECUTE FUNCTION sysforest_carbon_immutable();
SQL);
        DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => 'carbon.manage',
            'description' => 'Parametrizar referencias de carbono de la institución seleccionada.', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('code', 'carbon.manage')->value('id');
        foreach (DB::table('roles')->whereIn('name', ['SUPER_ADMIN', 'ORGANIZATION_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_estimates');
        Schema::dropIfExists('carbon_profiles');
        DB::statement('DROP FUNCTION IF EXISTS sysforest_carbon_immutable()');
    }
};
