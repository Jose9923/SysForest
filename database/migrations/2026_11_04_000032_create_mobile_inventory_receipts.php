<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mobile_inventory_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('inventory_id');
            $table->char('payload_hash', 64);
            $table->jsonb('result');
            $table->timestampsTz(6);
            $table->unique(['organization_id', 'operation_id']);
            $table->foreign(['organization_id', 'inventory_id'], 'mobile_inventory_receipt_tenant_fk')
                ->references(['organization_id', 'id'])->on('inventories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_inventory_receipts');
    }
};
