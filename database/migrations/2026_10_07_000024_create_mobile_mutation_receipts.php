<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mobile_mutation_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->string('action', 40);
            $table->uuid('target_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->uuid('tree_id');
            $table->jsonb('result');
            $table->timestampsTz(6);
            $table->unique(['organization_id', 'operation_id']);
            $table->foreign(['organization_id', 'tree_id'], 'mobile_mutation_tree_tenant_fk')
                ->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_mutation_receipts');
    }
};
