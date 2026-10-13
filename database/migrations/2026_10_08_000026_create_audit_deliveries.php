<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audit_deliveries', function (Blueprint $table): void {
            // La clave corresponde a una de siete tablas append-only; no duplica valores privados.
            $table->string('source', 20);
            $table->uuid('entry_id');
            $table->timestampTz('delivered_at', 6)->index();
            $table->primary(['source', 'entry_id']);
        });
        DB::statement("ALTER TABLE audit_deliveries ADD CONSTRAINT audit_delivery_source_check CHECK (
            source IN ('events', 'inspections', 'evidences', 'maintenances', 'publications', 'statuses', 'locations')
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_deliveries');
    }
};
