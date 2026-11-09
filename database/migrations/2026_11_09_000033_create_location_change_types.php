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
        Schema::create('location_change_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->integer('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['active', 'sort_order', 'name']);
        });
        DB::statement("ALTER TABLE location_change_types ADD CONSTRAINT location_change_types_metadata_check CHECK (
            sort_order >= 0 AND lock_version >= 1 AND length(btrim(name)) > 0
            AND (code ~ '^[A-Z][A-Z0-9_]{1,79}$' OR code IN ('correction','relocation','transplant','removal'))
        )");
        DB::unprepared('CREATE TRIGGER location_change_types_stable_code BEFORE UPDATE ON location_change_types FOR EACH ROW EXECUTE FUNCTION protect_forestry_catalog_code()');

        // Las claves existentes se referencian sin modificar ningún historial append-only.
        foreach ([['correction', 'Corrección'], ['relocation', 'Reubicación'], ['transplant', 'Trasplante'], ['removal', 'Remoción']] as $index => [$code, $name]) {
            DB::table('location_change_types')->insert([
                'id' => (string) Str::uuid(), 'code' => $code, 'name' => $name,
                'active' => true, 'sort_order' => ($index + 1) * 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::statement('ALTER TABLE tree_location_histories ALTER COLUMN change_type TYPE varchar(80)');
        DB::statement('ALTER TABLE tree_location_histories DROP CONSTRAINT tree_location_histories_values_check');
        DB::statement("ALTER TABLE tree_location_histories ADD CONSTRAINT tree_location_histories_values_check CHECK (
            jsonb_typeof(old_metadata) = 'object' AND jsonb_typeof(new_metadata) = 'object'
        )");
        Schema::table('tree_location_histories', function (Blueprint $table): void {
            $table->foreign('change_type', 'tree_location_histories_change_type_foreign')
                ->references('code')->on('location_change_types')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        if (DB::table('tree_location_histories')->whereNotNull('change_type')
            ->whereNotIn('change_type', ['correction', 'relocation', 'transplant'])->exists()) {
            throw new RuntimeException('El catálogo tiene referencias de nuevos tipos. La reversión no puede eliminar su significado histórico.');
        }

        Schema::table('tree_location_histories', function (Blueprint $table): void {
            $table->dropForeign('tree_location_histories_change_type_foreign');
        });
        DB::statement('ALTER TABLE tree_location_histories DROP CONSTRAINT tree_location_histories_values_check');
        DB::statement("ALTER TABLE tree_location_histories ADD CONSTRAINT tree_location_histories_values_check CHECK (
            (change_type IS NULL OR change_type IN ('correction','relocation','transplant'))
            AND jsonb_typeof(old_metadata) = 'object' AND jsonb_typeof(new_metadata) = 'object'
        )");
        DB::statement('ALTER TABLE tree_location_histories ALTER COLUMN change_type TYPE varchar(20)');
        Schema::dropIfExists('location_change_types');
    }
};
