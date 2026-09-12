<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 80)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at']);
            $table->index('expires_at');
        });

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('auth_session_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['auth_session_id', 'created_at']);
        });

        // La familia es la sesión. Los hashes consumidos se conservan para detectar reuso.
        DB::statement('CREATE UNIQUE INDEX refresh_tokens_one_current_per_session ON refresh_tokens (auth_session_id) WHERE consumed_at IS NULL');
        DB::statement("ALTER TABLE refresh_tokens ADD CONSTRAINT refresh_tokens_hash_check CHECK (token_hash ~ '^[a-f0-9]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('auth_sessions');
    }
};
