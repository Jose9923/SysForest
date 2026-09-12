<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('email', 254);
            $table->uuid('role_id');
            $table->string('scope', 20)->default('organization');
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'email']);
            $table->index('expires_at');
        });

        Schema::create('account_action_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // La dirección emitida impide verificar un correo cambiado después de generar el enlace.
            $table->string('email', 254);
            $table->string('purpose', 30);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'purpose', 'consumed_at']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_scope_check CHECK (scope = 'organization')");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_email_check CHECK (email = lower(btrim(email)))");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_hash_check CHECK (token_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_dates_check CHECK (expires_at > created_at AND (accepted_at IS NULL OR revoked_at IS NULL))');
        DB::statement('CREATE UNIQUE INDEX invitations_pending_email_unique ON invitations (organization_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
        DB::statement("ALTER TABLE account_action_tokens ADD CONSTRAINT action_tokens_purpose_check CHECK (purpose IN ('verification', 'password_reset'))");
        DB::statement("ALTER TABLE account_action_tokens ADD CONSTRAINT action_tokens_email_check CHECK (email = lower(btrim(email)))");
        DB::statement("ALTER TABLE account_action_tokens ADD CONSTRAINT action_tokens_hash_check CHECK (token_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE account_action_tokens ADD CONSTRAINT action_tokens_expiry_check CHECK (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_action_tokens');
        Schema::dropIfExists('invitations');
    }
};
