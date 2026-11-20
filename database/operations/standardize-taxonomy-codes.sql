-- SYSFOREST: retirar ORNATO de los códigos de los tres catálogos taxonómicos.
-- Primero publicar el código compatible y aplicar la migración 000038.
-- Ejecutar con el propietario/migrador de la base, después de un respaldo.
-- sysforest_editor no tiene permiso para esta operación de mantenimiento.
-- Solo cambia code, lock_version y updated_at; conserva identidades y relaciones.
-- Los códigos anteriores quedan como alias técnicos para archivos/enlaces antiguos.
-- Es repetible: si no quedan códigos con ORNATO, no cambia registros ni contadores.
-- Con psql: usar -X -v ON_ERROR_STOP=1 -f standardize-taxonomy-codes.sql.

BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
SET LOCAL search_path = public, pg_temp;

DO $check$
BEGIN
    IF to_regclass('public.taxonomy_code_sequences') IS NULL
        OR to_regclass('public.taxonomy_code_aliases') IS NULL
        OR NOT EXISTS (SELECT 1 FROM public.migrations
            WHERE migration = '2026_11_20_000038_add_taxonomy_code_sequences_and_aliases') THEN
        RAISE EXCEPTION 'Primero publicar la versión compatible y aplicar la migración 000038.';
    END IF;
END;
$check$;

LOCK TABLE families, species, species_common_names,
    taxonomy_code_sequences, taxonomy_code_aliases IN ACCESS EXCLUSIVE MODE;

CREATE TEMP TABLE sysforest_taxonomy_code_changes (
    catalog text NOT NULL,
    id bigint NOT NULL,
    external_id uuid NOT NULL,
    old_code text NOT NULL,
    new_code text NOT NULL,
    old_version bigint NOT NULL,
    preserved_values jsonb NOT NULL,
    PRIMARY KEY (catalog, id),
    UNIQUE (catalog, new_code)
) ON COMMIT DROP;

DO $standardize$
DECLARE
    spec record;
    item record;
    next_value bigint;
    new_code text;
    occupied boolean;
    alias_target bigint;
    updated_count bigint;
    expected_count bigint;
    request_id uuid := gen_random_uuid();
BEGIN
    FOR spec IN SELECT * FROM (VALUES
        ('families', 'families', 'family_id', 'FAMILY_', 'families_stable_code'),
        ('species', 'species', 'species_id', 'SPECIES_', 'species_stable_code'),
        ('common-names', 'species_common_names', 'common_name_id', 'COMMON_', 'species_common_names_stable_code')
    ) AS catalogs(catalog, table_name, reference_column, prefix, trigger_name)
    LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_trigger
            WHERE tgrelid = to_regclass('public.' || spec.table_name)
                AND tgname = spec.trigger_name AND tgenabled = 'O' AND NOT tgisinternal) THEN
            RAISE EXCEPTION 'La protección de código no está habilitada: %', spec.table_name;
        END IF;
        SELECT value INTO STRICT next_value FROM taxonomy_code_sequences
            WHERE catalog = spec.catalog FOR UPDATE;

        FOR item IN EXECUTE format(
            'SELECT t.*, to_jsonb(t) - ARRAY[''code'',''lock_version'',''updated_at''] AS preserved_values
             FROM public.%I t WHERE code ILIKE ''%%ORNATO%%'' ORDER BY id', spec.table_name)
        LOOP
            LOOP
                IF next_value = 9223372036854775807 THEN
                    RAISE EXCEPTION 'El consecutivo % alcanzó su límite.', spec.catalog;
                END IF;
                next_value := next_value + 1;
                -- lpad trunca cadenas largas: usar longitud mínima, no longitud fija.
                new_code := spec.prefix || lpad(next_value::text, greatest(6, length(next_value::text)), '0');
                EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I WHERE code = $1)', spec.table_name)
                    INTO occupied USING new_code;
                EXIT WHEN NOT occupied AND NOT EXISTS (
                    SELECT 1 FROM taxonomy_code_aliases a WHERE a.catalog = spec.catalog AND a.code = new_code);
            END LOOP;

            EXECUTE format('SELECT %I FROM taxonomy_code_aliases WHERE catalog = $1 AND code = $2', spec.reference_column)
                INTO alias_target USING spec.catalog, item.code;
            IF alias_target IS NOT NULL AND alias_target <> item.id THEN
                RAISE EXCEPTION 'El código anterior % ya corresponde a otra identidad.', item.code;
            END IF;
            IF alias_target IS NULL THEN
                EXECUTE format('INSERT INTO taxonomy_code_aliases (catalog, code, %I) VALUES ($1,$2,$3)', spec.reference_column)
                    USING spec.catalog, item.code, item.id;
            END IF;
            INSERT INTO sysforest_taxonomy_code_changes
                VALUES (spec.catalog, item.id, item.external_id, item.code, new_code,
                    item.lock_version, item.preserved_values);
        END LOOP;

        SELECT count(*) INTO expected_count FROM sysforest_taxonomy_code_changes WHERE catalog = spec.catalog;
        IF expected_count = 0 THEN
            CONTINUE;
        END IF;

        -- Excepción limitada a este trigger y a esta transacción; las FK siguen activas.
        EXECUTE format('ALTER TABLE public.%I DISABLE TRIGGER %I', spec.table_name, spec.trigger_name);
        EXECUTE format(
            'UPDATE public.%I t SET code = m.new_code, lock_version = t.lock_version + 1,
                updated_at = CURRENT_TIMESTAMP
             FROM sysforest_taxonomy_code_changes m
             WHERE m.catalog = $1 AND t.id = m.id AND t.external_id = m.external_id
                AND t.code = m.old_code AND t.lock_version = m.old_version', spec.table_name)
            USING spec.catalog;
        GET DIAGNOSTICS updated_count = ROW_COUNT;
        IF updated_count <> expected_count THEN
            RAISE EXCEPTION 'Cambió la preimagen de %: se esperaba %, se actualizó %.',
                spec.catalog, expected_count, updated_count;
        END IF;
        EXECUTE format('ALTER TABLE public.%I ENABLE TRIGGER %I', spec.table_name, spec.trigger_name);

        EXECUTE format(
            'SELECT EXISTS (SELECT 1 FROM public.%I t
             JOIN sysforest_taxonomy_code_changes m ON m.catalog = $1 AND t.id = m.id
             WHERE (to_jsonb(t) - ARRAY[''code'',''lock_version'',''updated_at'']) IS DISTINCT FROM m.preserved_values
                OR t.code <> m.new_code OR t.lock_version <> m.old_version + 1)', spec.table_name)
            INTO occupied USING spec.catalog;
        IF occupied THEN
            RAISE EXCEPTION 'Falló la comprobación de conservación de %.', spec.catalog;
        END IF;
        UPDATE taxonomy_code_sequences SET value = next_value WHERE catalog = spec.catalog;
    END LOOP;

    INSERT INTO security_events (external_id, event, actor_user_id, organization_id, metadata,
        created_at, resource_type, resource_id, before_values, after_values, context)
    SELECT gen_random_uuid(), 'catalog.code_standardized', NULL, NULL,
        jsonb_build_object('catalog_key', 'taxonomy.' || m.catalog,
            'resource_id', m.external_id::text, 'resource_code', m.new_code,
            'reason', 'Normalización de códigos ORNATO solicitada; identidad y relaciones conservadas. SQL standardize-taxonomy-codes.'),
        CURRENT_TIMESTAMP, 'catalog', m.external_id::text,
        jsonb_build_object('code', m.old_code, 'lock_version', m.old_version),
        jsonb_build_object('code', m.new_code, 'lock_version', m.old_version + 1),
        jsonb_build_object('request_id', request_id)
    FROM sysforest_taxonomy_code_changes m;
END;
$standardize$;

-- Resultado de la operación; confirmar que el cliente finaliza con COMMIT sin errores.
SELECT catalog AS catalogo, count(*) AS codigos_actualizados,
    min(new_code) AS primer_codigo, max(new_code) AS ultimo_codigo
FROM sysforest_taxonomy_code_changes GROUP BY catalog ORDER BY catalog;

COMMIT;
