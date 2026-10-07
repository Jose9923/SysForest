<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mobile_photo_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('client_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->uuid('tree_id');
            $table->uuid('evidence_id');
            $table->jsonb('result');
            $table->timestampsTz(6);
            $table->unique(['organization_id', 'client_id']);
            $table->unique('evidence_id');
            $table->foreign(['organization_id', 'tree_id'], 'mobile_photo_receipt_tree_tenant_fk')
                ->references(['organization_id', 'id'])->on('trees')->restrictOnDelete();
            $table->foreign(['organization_id', 'evidence_id'], 'mobile_photo_receipt_evidence_tenant_fk')
                ->references(['organization_id', 'id'])->on('evidences')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_photo_receipts');
    }
};
