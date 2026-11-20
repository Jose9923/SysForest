<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement('LOCK TABLE taxonomy_code_sequences, taxonomy_code_aliases, placements IN ACCESS EXCLUSIVE MODE');
        Schema::rename('taxonomy_code_sequences', 'catalog_code_sequences');
        Schema::rename('taxonomy_code_aliases', 'catalog_code_aliases');

        DB::unprepared(<<<'SQL'
            ALTER TABLE catalog_code_sequences DROP CONSTRAINT taxonomy_code_sequence_valid;
            ALTER TABLE catalog_code_sequences ADD CONSTRAINT catalog_code_sequence_valid
                CHECK (catalog IN ('families','species','common-names','placements') AND value >= 0);
            ALTER TABLE catalog_code_aliases DISABLE TRIGGER taxonomy_code_alias_append_only;
            ALTER TABLE catalog_code_aliases ADD COLUMN source_hash varchar(64);
            UPDATE catalog_code_aliases SET source_hash = encode(sha256(convert_to(code, 'UTF8')), 'hex');
            ALTER TABLE catalog_code_aliases ALTER COLUMN source_hash SET NOT NULL;
            ALTER TABLE catalog_code_aliases DROP CONSTRAINT taxonomy_code_alias_format;
            ALTER TABLE catalog_code_aliases DROP CONSTRAINT taxonomy_code_alias_reference;
            ALTER TABLE catalog_code_aliases DROP CONSTRAINT taxonomy_code_aliases_catalog_code_unique;
            ALTER TABLE catalog_code_aliases DROP COLUMN code;
            SQL);

        Schema::table('catalog_code_aliases', function (Blueprint $table): void {
            $table->foreignId('placement_id')->nullable()->constrained('placements')->restrictOnDelete();
            $table->index('placement_id');
            $table->unique(['catalog', 'source_hash']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE catalog_code_aliases ADD CONSTRAINT catalog_code_alias_reference CHECK (
                (catalog = 'families' AND family_id IS NOT NULL AND species_id IS NULL AND common_name_id IS NULL AND placement_id IS NULL)
                OR (catalog = 'species' AND family_id IS NULL AND species_id IS NOT NULL AND common_name_id IS NULL AND placement_id IS NULL)
                OR (catalog = 'common-names' AND family_id IS NULL AND species_id IS NULL AND common_name_id IS NOT NULL AND placement_id IS NULL)
                OR (catalog = 'placements' AND family_id IS NULL AND species_id IS NULL AND common_name_id IS NULL AND placement_id IS NOT NULL)
            );
            ALTER TABLE catalog_code_aliases ADD CONSTRAINT catalog_code_alias_hash
                CHECK (source_hash ~ '^[a-f0-9]{64}$');
            DROP TRIGGER taxonomy_code_alias_append_only ON catalog_code_aliases;
            DROP FUNCTION protect_taxonomy_code_alias();
            CREATE OR REPLACE FUNCTION protect_catalog_code_alias() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Las referencias de origen del catálogo son inmutables' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER catalog_code_alias_append_only BEFORE UPDATE OR DELETE ON catalog_code_aliases
                FOR EACH ROW EXECUTE FUNCTION protect_catalog_code_alias();
            INSERT INTO catalog_code_sequences (catalog, value)
                SELECT 'placements', COALESCE(MAX(substring(code FROM '^PLACEMENT_([0-9]{6,})$')::numeric), 0)::bigint
                FROM placements WHERE code ~ '^PLACEMENT_[0-9]{6,}$';
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las referencias de origen no se pueden reconstruir desde sus huellas. Recupera el respaldo compatible para revertir esta migración.');
    }
};
