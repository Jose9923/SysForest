<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->string('client_type', 12)->default('web');
            $table->foreignUuid('organization_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_client_context_check CHECK (
            (client_type = 'web' AND organization_id IS NULL)
            OR (client_type = 'mobile' AND organization_id IS NOT NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auth_sessions DROP CONSTRAINT IF EXISTS auth_sessions_client_context_check');
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['client_type', 'organization_id']);
        });
    }
};
