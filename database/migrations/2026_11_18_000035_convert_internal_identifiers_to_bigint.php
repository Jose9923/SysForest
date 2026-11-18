<?php

declare(strict_types=1);

use App\Support\Identifiers\IdentifierSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /** Cambia solo identidades relacionales; conserva UUID externos, snapshots y recibos. */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('La conversión de identificadores requiere PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '10s'");
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('sysforest:internal-identifiers:v1', 0))");
            $columns = $this->conversionColumns();
            $tables = array_keys($columns);
            sort($tables);
            $this->validateSchema($columns);

            // El operador detiene antes web/colas/scheduler: un cambio de PK no es
            // compatible con escritores de la versión anterior durante el corte.
            foreach ($tables as $table) {
                DB::statement('LOCK TABLE '.$this->table($table).' IN ACCESS EXCLUSIVE MODE');
            }
            $counts = [];
            foreach ($tables as $table) {
                $counts[$table] = DB::table($table)->count();
            }
            $foreignKeys = $this->foreignKeys($columns);
            $triggers = $this->triggers($tables);
            $function = DB::selectOne("SELECT pg_get_functiondef('public.protect_import_row()'::regprocedure) AS definition")->definition;
            $replacement = preg_replace('/\binspection_tree\s+uuid\s*;/', 'inspection_tree bigint;', $function, 1, $replaced);
            if ($replaced !== 1 || !is_string($replacement)) {
                throw new RuntimeException('La función de importaciones no coincide con el contrato UUID esperado.');
            }

            DB::statement('CREATE TEMP TABLE sysforest_identifier_map (entity text NOT NULL, external_id uuid NOT NULL, internal_id bigint NOT NULL, PRIMARY KEY (entity, external_id), UNIQUE (entity, internal_id)) ON COMMIT DROP');
            foreach (IdentifierSchema::TABLES as $table) {
                // El orden UUID conserva desempates y cursores anteriores dentro
                // de cada entidad; la secuencia continuará después del máximo.
                DB::statement('INSERT INTO pg_temp.sysforest_identifier_map SELECT ?, id, row_number() OVER (ORDER BY id) FROM '.$this->table($table), [$table]);
            }
            DB::unprepared(<<<'SQL'
CREATE FUNCTION pg_temp.sysforest_identifier_to_bigint(entity_name text, original_id uuid)
RETURNS bigint LANGUAGE plpgsql STABLE STRICT AS $lookup$
DECLARE mapped_id bigint;
BEGIN
    SELECT internal_id INTO mapped_id FROM pg_temp.sysforest_identifier_map
    WHERE entity = entity_name AND external_id = original_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Referencia interna sin correspondencia en %; no se permite descartar datos.', entity_name;
    END IF;
    RETURN mapped_id;
END;
$lookup$;
SQL);

            foreach ($triggers as $trigger) {
                // DISABLE no retira las dependencias de UPDATE OF/WHEN que
                // impedirían ALTER TYPE; se restaura la definición exacta.
                DB::statement('DROP TRIGGER '.$this->identifier($trigger->trigger_name).' ON '.$this->table($trigger->table_name));
            }
            foreach ($foreignKeys as $foreignKey) {
                DB::statement('ALTER TABLE '.$this->table($foreignKey->table_name).' DROP CONSTRAINT '.$this->identifier($foreignKey->constraint_name));
            }
            foreach (IdentifierSchema::TABLES as $table) {
                DB::statement('ALTER TABLE '.$this->table($table).' ADD COLUMN external_id uuid');
                DB::statement('UPDATE '.$this->table($table).' SET external_id = id');
                DB::statement('ALTER TABLE '.$this->table($table).' ALTER COLUMN external_id SET NOT NULL, ALTER COLUMN external_id SET DEFAULT gen_random_uuid(), ADD CONSTRAINT '.$this->identifier($table.'_external_id_unique').' UNIQUE (external_id)');
            }
            foreach ($tables as $table) {
                $alterations = [];
                foreach ($columns[$table] as $column => $target) {
                    $name = $this->identifier($column);
                    $expression = is_string($target) ? $this->literal($target) : $this->polymorphicExpression($target);
                    $alterations[] = 'ALTER COLUMN '.$name.' DROP DEFAULT';
                    $alterations[] = 'ALTER COLUMN '.$name.' TYPE bigint USING pg_temp.sysforest_identifier_to_bigint('.$expression.', '.$name.')';
                }
                // Convertir todas las columnas de una tabla juntas preserva los
                // checks que comparan referencias entre sí, además de índices/PK.
                DB::statement('ALTER TABLE '.$this->table($table).' '.implode(', ', $alterations));
            }
            foreach (IdentifierSchema::TABLES as $table) {
                DB::statement('ALTER TABLE '.$this->table($table).' ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY');
                DB::select('SELECT setval(pg_get_serial_sequence(?, ?), GREATEST(COALESCE(MAX(id), 0), 1), COUNT(*) > 0) FROM '.$this->table($table), ['public.'.$table, 'id']);
                $this->grantSequenceAccess($table);
            }

            DB::unprepared($replacement);
            foreach ($foreignKeys as $foreignKey) {
                DB::statement('ALTER TABLE '.$this->table($foreignKey->table_name).' ADD CONSTRAINT '.$this->identifier($foreignKey->constraint_name).' '.$foreignKey->definition);
            }
            foreach ($triggers as $trigger) {
                DB::unprepared($trigger->definition);
                if ($trigger->comment !== null) {
                    DB::statement('COMMENT ON TRIGGER '.$this->identifier($trigger->trigger_name).' ON '.$this->table($trigger->table_name).' IS '.$this->literal($trigger->comment));
                }
                $action = match ($trigger->enabled) {
                    'D' => 'DISABLE',
                    'R' => 'ENABLE REPLICA',
                    'A' => 'ENABLE ALWAYS',
                    default => 'ENABLE',
                };
                DB::statement('ALTER TABLE '.$this->table($trigger->table_name).' '.$action.' TRIGGER '.$this->identifier($trigger->trigger_name));
            }
            foreach ($counts as $table => $count) {
                if (DB::table($table)->count() !== $count) {
                    throw new RuntimeException('La conversión alteró el número de filas de '.$table.'.');
                }
            }
            foreach (IdentifierSchema::TABLES as $table) {
                $mismatches = DB::selectOne('SELECT COUNT(*) AS total FROM '.$this->table($table).' t LEFT JOIN pg_temp.sysforest_identifier_map m ON m.entity = ? AND m.external_id = t.external_id WHERE m.internal_id IS DISTINCT FROM t.id', [$table]);
                if ((int) $mismatches->total !== 0) {
                    throw new RuntimeException('La correspondencia externa/interna no se conservó en '.$table.'.');
                }
            }
            DB::statement('DROP FUNCTION pg_temp.sysforest_identifier_to_bigint(text, uuid)');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('La conversión de PK requiere restauración coordinada del respaldo y del código; no se revierte descartando identidades o datos.');
    }

    /** @return array<string, array<string, string|array>> */
    private function conversionColumns(): array
    {
        $columns = IdentifierSchema::REFERENCES;
        foreach (IdentifierSchema::TABLES as $table) {
            $columns[$table]['id'] = $table;
        }
        foreach (IdentifierSchema::POLYMORPHIC_REFERENCES as $table => $references) {
            foreach ($references as $column => $reference) {
                $columns[$table][$column] = $reference;
            }
        }

        return $columns;
    }

    /** Rechaza deriva de esquema en vez de convertir UUID de correlación por heurística. */
    private function validateSchema(array $columns): void
    {
        $actual = DB::select(<<<'SQL'
SELECT c.relname AS table_name, a.attname AS column_name, format_type(a.atttypid, a.atttypmod) AS data_type
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
SQL);
        $seen = [];
        foreach ($actual as $column) {
            $table = $column->table_name;
            $name = $column->column_name;
            if (isset($columns[$table][$name])) {
                if ($column->data_type !== 'uuid') {
                    throw new RuntimeException('Tipo inesperado antes de convertir '.$table.'.'.$name.'.');
                }
                $seen[$table][$name] = true;
            } elseif ($column->data_type === 'uuid'
                && !in_array($name, IdentifierSchema::UUID_CORRELATIONS[$table] ?? [], true)) {
                throw new RuntimeException('UUID no clasificado en IdentifierSchema: '.$table.'.'.$name.'.');
            }
            if ($name === 'external_id' && in_array($table, IdentifierSchema::TABLES, true)) {
                throw new RuntimeException('La columna external_id ya existe; revisar el estado antes de convertir.');
            }
        }
        foreach ($columns as $table => $references) {
            foreach ($references as $column => $target) {
                if (!isset($seen[$table][$column])) {
                    throw new RuntimeException('Falta una columna declarada en IdentifierSchema: '.$table.'.'.$column.'.');
                }
                if (is_array($target)) {
                    $discriminator = $target['discriminator'];
                    if (DB::table($table)->whereNotNull($column)->whereNotIn($discriminator, array_keys($target['targets']))->exists()) {
                        throw new RuntimeException('Discriminador desconocido para '.$table.'.'.$column.'.');
                    }
                }
            }
        }
    }

    private function foreignKeys(array $columns): array
    {
        $foreignKeys = DB::select(<<<'SQL'
SELECT child_namespace.nspname AS schema_name, child.relname AS table_name,
    parent_namespace.nspname AS parent_schema, parent.relname AS parent_table,
    k.conname AS constraint_name, pg_get_constraintdef(k.oid, true) AS definition,
    ARRAY(SELECT a.attname FROM unnest(k.conkey) WITH ORDINALITY q(attnum, ord)
        JOIN pg_attribute a ON a.attrelid = k.conrelid AND a.attnum = q.attnum ORDER BY q.ord)::text AS child_columns,
    ARRAY(SELECT a.attname FROM unnest(k.confkey) WITH ORDINALITY q(attnum, ord)
        JOIN pg_attribute a ON a.attrelid = k.confrelid AND a.attnum = q.attnum ORDER BY q.ord)::text AS parent_columns
FROM pg_constraint k JOIN pg_class child ON child.oid = k.conrelid
JOIN pg_namespace child_namespace ON child_namespace.oid = child.relnamespace
JOIN pg_class parent ON parent.oid = k.confrelid
JOIN pg_namespace parent_namespace ON parent_namespace.oid = parent.relnamespace
WHERE k.contype = 'f' ORDER BY child_namespace.nspname, child.relname, k.conname
SQL);
        $affected = [];
        foreach ($foreignKeys as $foreignKey) {
            $childColumns = explode(',', trim($foreignKey->child_columns, '{}'));
            $parentColumns = explode(',', trim($foreignKey->parent_columns, '{}'));
            $changesChild = $foreignKey->schema_name === 'public'
                && array_intersect($childColumns, array_keys($columns[$foreignKey->table_name] ?? [])) !== [];
            $changesParent = $foreignKey->parent_schema === 'public'
                && array_intersect($parentColumns, array_keys($columns[$foreignKey->parent_table] ?? [])) !== [];
            if ($changesChild || $changesParent) {
                if (!$changesChild || !$changesParent || $foreignKey->schema_name !== 'public' || $foreignKey->parent_schema !== 'public') {
                    throw new RuntimeException('FK fuera del manifiesto de conversión: '.$foreignKey->constraint_name.'.');
                }
                $affected[] = $foreignKey;
            }
        }

        return $affected;
    }

    private function triggers(array $tables): array
    {
        return DB::table('pg_trigger as g')->join('pg_class as t', 't.oid', '=', 'g.tgrelid')
            ->join('pg_namespace as n', 'n.oid', '=', 't.relnamespace')
            ->where('n.nspname', 'public')->whereIn('t.relname', $tables)->where('g.tgisinternal', false)
            ->orderBy('t.relname')->orderBy('g.tgname')
            ->get([
                't.relname as table_name', 'g.tgname as trigger_name', 'g.tgenabled as enabled',
                DB::raw('pg_get_triggerdef(g.oid, true) as definition'),
                DB::raw("obj_description(g.oid, 'pg_trigger') as comment"),
            ])->all();
    }

    /** Conserva el acceso de los insertadores existentes a la nueva secuencia. */
    private function grantSequenceAccess(string $table): void
    {
        $sequence = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', ['public.'.$table, 'id'])->name;
        $roles = DB::select(<<<'SQL'
SELECT DISTINCT privilege.grantee, r.rolname
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) privilege
LEFT JOIN pg_roles r ON r.oid = privilege.grantee
WHERE n.nspname = 'public' AND c.relname = ? AND privilege.privilege_type = 'INSERT'
UNION
SELECT DISTINCT privilege.grantee, r.rolname
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
CROSS JOIN LATERAL aclexplode(a.attacl) privilege
LEFT JOIN pg_roles r ON r.oid = privilege.grantee
WHERE n.nspname = 'public' AND c.relname = ? AND privilege.privilege_type = 'INSERT'
SQL, [$table, $table]);
        foreach ($roles as $role) {
            $grantee = (int) $role->grantee === 0 ? 'PUBLIC' : $this->identifier($role->rolname);
            // pg_get_serial_sequence devuelve un identificador cualificado ya escapado por PostgreSQL.
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE '.$sequence.' TO '.$grantee);
        }
    }

    private function polymorphicExpression(array $reference): string
    {
        $expression = 'CASE '.$this->identifier($reference['discriminator']);
        foreach ($reference['targets'] as $value => $table) {
            $expression .= ' WHEN '.$this->literal($value).' THEN '.$this->literal($table);
        }

        return $expression.' END';
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function table(string $name): string
    {
        return 'public.'.$this->identifier($name);
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
