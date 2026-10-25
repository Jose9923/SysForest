<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('carbon_profiles', function (Blueprint $table): void {
            $table->string('validation_status', 40)->default('professional');
            $table->string('source_package', 80)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
        DB::statement("ALTER TABLE carbon_profiles ADD CONSTRAINT carbon_profile_validation_check CHECK (validation_status IN ('professional','bibliographic','pending_density','pending_identification','unsupported'))");
        DB::statement("ALTER TABLE carbon_profiles ADD CONSTRAINT carbon_profile_provenance_check CHECK ((validation_status = 'professional' AND created_by IS NOT NULL AND source_package IS NULL) OR (validation_status <> 'professional' AND created_by IS NULL AND source_package IS NOT NULL))");
        DB::statement("ALTER TABLE carbon_profiles ADD CONSTRAINT carbon_profile_pending_check CHECK (validation_status NOT IN ('pending_density','pending_identification','unsupported') OR NOT applicable)");
    }

    public function down(): void
    {
        // Las precargas sin autor humano impiden retirar la procedencia sin perder semántica.
        if (DB::table('carbon_profiles')->whereNull('created_by')->exists()) {
            throw new RuntimeException('No se puede retirar la procedencia mientras existan referencias bibliográficas conservadas.');
        }
        DB::statement('ALTER TABLE carbon_profiles DROP CONSTRAINT carbon_profile_validation_check, DROP CONSTRAINT carbon_profile_provenance_check, DROP CONSTRAINT carbon_profile_pending_check');
        Schema::table('carbon_profiles', function (Blueprint $table): void {
            $table->dropColumn(['validation_status', 'source_package']);
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
