<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const CATALOGS = [
        'families' => ['families', 'family_id', 'FAMILY_'],
        'species' => ['species', 'species_id', 'SPECIES_'],
        'common-names' => ['species_common_names', 'common_name_id', 'COMMON_'],
        'placements' => ['placements', 'placement_id', 'PLACEMENT_'],
    ];

    public function up(): void
    {
        $path = __DIR__.'/data/2026_11_20_reference_catalog_cleanup.json';
        if (hash_file('sha256', $path) !== 'a3c14ba4f13368959816353f5865203c0d451d0deb0ae1962ac29b9962b4cfd3') {
            throw new RuntimeException('La correspondencia de catálogos no coincide con su huella aprobada.');
        }
        $mapping = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (count($mapping['rows']) !== 315) {
            throw new RuntimeException('La correspondencia de catálogos está incompleta.');
        }
        DB::statement("SET LOCAL lock_timeout = '10s'");
        DB::statement('LOCK TABLE families, species, species_common_names, placements, catalog_code_sequences, catalog_code_aliases IN ACCESS EXCLUSIVE MODE');
        $plans = [];
        $seen = [];
        foreach ($mapping['rows'] as $source) {
            $kind = $source['kind'];
            [$table, $foreignKey, $prefix] = self::CATALOGS[$kind];
            $hashes = [hash('sha256', $source['source_key']), $source['old_hash']];
            $ids = DB::table($table)->where('code', $source['source_key'])
                ->orWhereRaw("encode(sha256(convert_to(code, 'UTF8')), 'hex') = ?", [$source['old_hash']])
                ->pluck('id')->merge(DB::table('catalog_code_aliases')->where('catalog', $kind)
                    ->whereIn('source_hash', $hashes)->pluck($foreignKey))
                ->map(fn ($id) => (string) $id)->unique()->values();
            if ($ids->count() > 1) {
                throw new RuntimeException('Origen ambiguo en el catálogo '.$kind.'.');
            }
            if ($ids->isEmpty()) {
                continue; // Una instalación nueva aplica las migraciones antes de sembrar.
            }
            $id = $ids->first();
            if (isset($seen[$kind][$id])) {
                throw new RuntimeException('Dos orígenes apuntan al mismo registro en '.$kind.'.');
            }
            $seen[$kind][$id] = true;
            $before = (array) DB::table($table)->where('id', $id)->firstOrFail();
            foreach ($hashes as $hash) {
                $this->registerHash($kind, $table, $foreignKey, $hash, $id);
            }
            $values = [];
            if (in_array(hash('sha256', $before['code']), $hashes, true)) {
                $values['code'] = $this->nextCode($kind, $table, $prefix);
            }
            if (hash('sha256', $before['description'] ?? '') === $source['old_description_sha256']) {
                $values['description'] = $source['neutral_description'];
            }
            if ($values !== []) {
                $plans[] = compact('kind', 'table', 'id', 'before', 'values');
            }
        }
        $codeTables = array_unique(array_column(array_filter($plans, fn ($plan) => isset($plan['values']['code'])), 'table'));
        foreach ($codeTables as $table) {
            $trigger = $table.'_stable_code';
            $enabled = DB::selectOne('SELECT tgenabled FROM pg_trigger WHERE tgrelid = to_regclass(?) AND tgname = ? AND NOT tgisinternal', ['public.'.$table, $trigger]);
            if ($enabled === null || $enabled->tgenabled !== 'O') {
                throw new RuntimeException('Falta la protección activa del código de '.$table.'.');
            }
            DB::statement('ALTER TABLE '.$table.' DISABLE TRIGGER '.$trigger);
        }
        $requestId = DB::selectOne('SELECT gen_random_uuid() AS value')->value;
        $now = DB::selectOne('SELECT CURRENT_TIMESTAMP AS value')->value;
        foreach ($plans as $plan) {
            ['kind' => $kind, 'table' => $table, 'id' => $id, 'before' => $before, 'values' => $values] = $plan;
            $values['lock_version'] = $before['lock_version'] + 1;
            $values['updated_at'] = $now;
            $updated = DB::table($table)->where('id', $id)->where('external_id', $before['external_id'])
                ->where('code', $before['code'])->where('lock_version', $before['lock_version'])->update($values);
            if ($updated !== 1) {
                throw new RuntimeException('Cambió la preimagen del catálogo '.$kind.'.');
            }
            $after = (array) DB::table($table)->where('id', $id)->firstOrFail();
            $allowed = array_fill_keys(['code', 'description', 'lock_version', 'updated_at'], true);
            if (array_diff_key($before, $allowed) !== array_diff_key($after, $allowed)) {
                throw new RuntimeException('Cambió una identidad o relación del catálogo '.$kind.'.');
            }
            $auditKeys = array_fill_keys(['code', 'description', 'lock_version'], true);
            DB::table('security_events')->insert([
                'external_id' => DB::raw('gen_random_uuid()'),
                'event' => 'catalog.reference_standardized',
                'actor_user_id' => null,
                'organization_id' => null,
                'resource_type' => 'catalog',
                'resource_id' => $after['external_id'],
                'metadata' => json_encode([
                    'catalog_key' => $kind === 'placements' ? $kind : 'taxonomy.'.$kind,
                    'resource_id' => $after['external_id'],
                    'resource_code' => $after['code'],
                    'reason' => 'Nomenclatura neutral del catálogo de referencia; identidades y relaciones conservadas.',
                ], JSON_THROW_ON_ERROR),
                'before_values' => json_encode(array_intersect_key($before, $auditKeys), JSON_THROW_ON_ERROR),
                'after_values' => json_encode(array_intersect_key($after, $auditKeys), JSON_THROW_ON_ERROR),
                'context' => json_encode(['request_id' => $requestId], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }
        foreach ($codeTables as $table) {
            DB::statement('ALTER TABLE '.$table.' ENABLE TRIGGER '.$table.'_stable_code');
        }
    }

    private function registerHash(string $kind, string $table, string $foreignKey, string $hash, string $id): void
    {
        $canonical = DB::table($table)->whereRaw("encode(sha256(convert_to(code, 'UTF8')), 'hex') = ?", [$hash])
            ->where('id', '<>', $id)->exists();
        $alias = DB::table('catalog_code_aliases')->where('catalog', $kind)->where('source_hash', $hash)->first();
        if ($canonical || ($alias !== null && (string) $alias->{$foreignKey} !== $id)) {
            throw new RuntimeException('La referencia de origen ya corresponde a otra identidad en '.$kind.'.');
        }
        if ($alias === null) {
            DB::table('catalog_code_aliases')->insert(['catalog' => $kind, 'source_hash' => $hash, $foreignKey => $id]);
        }
    }

    private function nextCode(string $kind, string $table, string $prefix): string
    {
        $value = DB::table('catalog_code_sequences')->where('catalog', $kind)->lockForUpdate()->value('value');
        if ($value === null) {
            throw new RuntimeException('Falta el contador del catálogo '.$kind.'.');
        }
        do {
            if ((int) $value === PHP_INT_MAX) {
                throw new RuntimeException('El consecutivo del catálogo alcanzó su límite.');
            }
            $value = (int) $value + 1;
            $code = $prefix.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
        } while (DB::table($table)->where('code', $code)->exists()
            || DB::table('catalog_code_aliases')->where('catalog', $kind)->where('source_hash', hash('sha256', $code))->exists());
        DB::table('catalog_code_sequences')->where('catalog', $kind)->update(['value' => $value]);

        return $code;
    }

    public function down(): void
    {
        throw new RuntimeException('La normalización preserva datos existentes y no se revierte automáticamente. Usa un respaldo compatible si necesitas recuperar el estado anterior.');
    }
};
