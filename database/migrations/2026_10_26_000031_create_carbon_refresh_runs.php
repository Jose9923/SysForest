<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('carbon_refresh_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedBigInteger('reference_version');
            $table->uuid('last_inspection_id')->nullable();
            $table->unsignedInteger('total');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('estimates_added')->default(0);
            $table->string('reason', 50)->nullable();
            $table->timestampTz('requested_at', 6);
            $table->timestampTz('dispatched_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'dispatched_at']);
        });
        DB::statement("ALTER TABLE carbon_refresh_runs ADD CHECK (status IN ('queued', 'running', 'completed', 'cancelled', 'failed'))");
        DB::statement('ALTER TABLE carbon_refresh_runs ADD CHECK (processed <= total AND estimates_added <= processed)');
        DB::statement("CREATE UNIQUE INDEX carbon_refresh_one_active ON carbon_refresh_runs (organization_id) WHERE status IN ('queued', 'running')");
        Schema::table('tree_inspections', function (Blueprint $table): void {
            $table->index(['organization_id', 'id', 'created_at'], 'inspection_carbon_refresh_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('tree_inspections', function (Blueprint $table): void {
            $table->dropIndex('inspection_carbon_refresh_cursor');
        });
        Schema::dropIfExists('carbon_refresh_runs');
    }
};
