<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // El máximo inicial y la creación de los contadores no compiten con altas del catálogo.
        DB::statement('LOCK TABLE families, species, species_common_names IN SHARE ROW EXCLUSIVE MODE');
        Schema::create('taxonomy_code_sequences', function (Blueprint $table): void {
            $table->string('catalog', 32)->primary();
            $table->bigInteger('value');
        });
        DB::statement("ALTER TABLE taxonomy_code_sequences ADD CONSTRAINT taxonomy_code_sequence_valid CHECK (catalog IN ('families','species','common-names') AND value >= 0)");

        Schema::create('taxonomy_code_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('catalog', 32);
            $table->string('code', 80);
            $table->foreignId('family_id')->nullable()->constrained('families')->restrictOnDelete();
            $table->foreignId('species_id')->nullable()->constrained('species')->restrictOnDelete();
            $table->foreignId('common_name_id')->nullable()->constrained('species_common_names')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['catalog', 'code']);
            $table->index('family_id');
            $table->index('species_id');
            $table->index('common_name_id');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE taxonomy_code_aliases ADD CONSTRAINT taxonomy_code_alias_reference CHECK (
                (catalog = 'families' AND family_id IS NOT NULL AND species_id IS NULL AND common_name_id IS NULL)
                OR (catalog = 'species' AND family_id IS NULL AND species_id IS NOT NULL AND common_name_id IS NULL)
                OR (catalog = 'common-names' AND family_id IS NULL AND species_id IS NULL AND common_name_id IS NOT NULL)
            );
            ALTER TABLE taxonomy_code_aliases ADD CONSTRAINT taxonomy_code_alias_format
                CHECK (code ~ '^[A-Z][A-Z0-9_]{1,79}$');
            CREATE OR REPLACE FUNCTION protect_taxonomy_code_alias() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Los códigos taxonómicos anteriores son inmutables' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER taxonomy_code_alias_append_only BEFORE UPDATE OR DELETE ON taxonomy_code_aliases
                FOR EACH ROW EXECUTE FUNCTION protect_taxonomy_code_alias();
            SQL);

        foreach ([
            ['families', 'families', 'FAMILY_'],
            ['species', 'species', 'SPECIES_'],
            ['common-names', 'species_common_names', 'COMMON_'],
        ] as [$catalog, $table, $prefix]) {
            DB::insert(
                "INSERT INTO taxonomy_code_sequences (catalog, value)
                 SELECT ?, COALESCE(MAX(substring(code FROM ?::text)::numeric), 0)::bigint
                 FROM {$table} WHERE code ~ ?",
                [$catalog, '^'.$prefix.'([0-9]{6,})$', '^'.$prefix.'[0-9]{6,}$'],
            );
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE taxonomy_code_aliases IN ACCESS EXCLUSIVE MODE');
        if (DB::table('taxonomy_code_aliases')->exists()) {
            throw new RuntimeException('No se pueden retirar las equivalencias taxonómicas existentes.');
        }
        Schema::dropIfExists('taxonomy_code_aliases');
        DB::statement('DROP FUNCTION protect_taxonomy_code_alias()');
        Schema::dropIfExists('taxonomy_code_sequences');
    }
};
