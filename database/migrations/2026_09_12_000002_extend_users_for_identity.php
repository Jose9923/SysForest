<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->index();
        });
        // El correo identifica una cuenta global incluso si cambia el uso de mayúsculas.
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');

        Schema::create('security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event', 80);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');
            $table->index(['event', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT security_events_metadata_object CHECK (jsonb_typeof(metadata) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('active');
        });
    }
};
