<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('condition_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('name', 255);
            $table->string('assessment_type', 30);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['id', 'assessment_type']);
            $table->index(['assessment_type', 'active', 'sort_order']);
        });
        Schema::create('conditions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('name', 255);
            $table->string('assessment_type', 30);
            $table->uuid('condition_category_id');
            $table->foreign(['condition_category_id', 'assessment_type'], 'conditions_category_assessment_fk')
                ->references(['id', 'assessment_type'])->on('condition_categories')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->index(['assessment_type', 'active', 'sort_order']);
        });
        foreach (['condition_categories', 'conditions'] as $name) {
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_code_check CHECK (code ~ '^[A-Z][A-Z0-9_]{1,79}$')");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_order_check CHECK (sort_order BETWEEN 0 AND 1000000)");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_name_check CHECK (btrim(name) <> '')");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_assessment_check CHECK (assessment_type IN ('physical_mechanical', 'phytosanitary'))");
            DB::unprepared("CREATE TRIGGER {$name}_stable_code BEFORE UPDATE ON {$name} FOR EACH ROW EXECUTE FUNCTION protect_forestry_catalog_code()");
        }
        DB::statement('CREATE UNIQUE INDEX condition_category_name_unique ON condition_categories (assessment_type, lower(btrim(name)))');
        DB::statement('CREATE UNIQUE INDEX condition_name_per_category_unique ON conditions (condition_category_id, lower(btrim(name)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('conditions');
        Schema::dropIfExists('condition_categories');
    }
};
