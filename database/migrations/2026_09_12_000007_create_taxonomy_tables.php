<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        foreach (['families', 'species', 'species_common_names'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->uuid('id')->primary();
                $table->string('code', 80)->unique();
                if ($name === 'species') {
                    $table->foreignUuid('family_id')->constrained()->restrictOnDelete();
                } elseif ($name === 'species_common_names') {
                    $table->foreignUuid('species_id')->constrained('species')->restrictOnDelete();
                    $table->boolean('is_primary')->default(false);
                }
                $table->string($name === 'species_common_names' ? 'name' : 'scientific_name', 255);
                $table->text('description')->nullable();
                $table->boolean('active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestampsTz();
                $table->index(['active', 'sort_order']);
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_code_check CHECK (code ~ '^[A-Z][A-Z0-9_]{1,79}$')");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_order_check CHECK (sort_order BETWEEN 0 AND 1000000)");
            $column = $name === 'species_common_names' ? 'name' : 'scientific_name';
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_name_check CHECK (btrim({$column}) <> '')");
            DB::unprepared("CREATE TRIGGER {$name}_stable_code BEFORE UPDATE ON {$name} FOR EACH ROW EXECUTE FUNCTION protect_forestry_catalog_code()");
        }
        DB::statement('CREATE UNIQUE INDEX families_scientific_name_unique ON families (lower(btrim(scientific_name)))');
        DB::statement('CREATE UNIQUE INDEX species_scientific_name_unique ON species (lower(btrim(scientific_name)))');
        DB::statement('CREATE UNIQUE INDEX species_common_name_unique ON species_common_names (species_id, lower(btrim(name)))');
        DB::statement('CREATE UNIQUE INDEX species_one_primary_name ON species_common_names (species_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('species_common_names');
        Schema::dropIfExists('species');
        Schema::dropIfExists('families');
    }
};
