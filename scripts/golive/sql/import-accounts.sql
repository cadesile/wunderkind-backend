-- Go-live step: load production's accounts (schema golive_export, restored from the stage
-- dump) into wk_golive's real tables, after purge-dev-users.sql has emptied them.
--
-- Columns are matched BY NAME, never by position: the two databases reached the same schema
-- by different migration histories, so column order can differ. A column that exists in the
-- export but not in wk_golive stops the import, since that means the schemas have drifted.
--
-- Ids are kept as they are (all UUIDs, except refresh_tokens.id, whose sequence is moved past
-- the imported rows). Each account's balance chain is therefore untouched.

\set ON_ERROR_STOP on
BEGIN;

DO $$ BEGIN
    IF current_database() <> 'wk_golive' THEN
        RAISE EXCEPTION 'import-accounts.sql must only run on wk_golive, not %', current_database();
    END IF;
    IF (SELECT count(*) FROM public."user") <> 0 OR (SELECT count(*) FROM public.club) <> 0 THEN
        RAISE EXCEPTION 'wk_golive still has players: run purge-dev-users.sql first';
    END IF;
END $$;

CREATE FUNCTION pg_temp.copy_table(t text) RETURNS bigint LANGUAGE plpgsql AS $fn$
DECLARE
    cols  text;
    extra text;
    n     bigint;
BEGIN
    SELECT string_agg(column_name, ', ') INTO extra
      FROM information_schema.columns e
     WHERE e.table_schema = 'golive_export' AND e.table_name = t
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns p
                        WHERE p.table_schema = 'public' AND p.table_name = t
                          AND p.column_name = e.column_name);
    IF extra IS NOT NULL THEN
        RAISE EXCEPTION 'schema drift: %.% exists in the prod export but not in wk_golive', t, extra;
    END IF;

    SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position) INTO cols
      FROM information_schema.columns
     WHERE table_schema = 'golive_export' AND table_name = t;

    EXECUTE format('INSERT INTO public.%I (%s) SELECT %s FROM golive_export.%I', t, cols, cols, t);
    GET DIAGNOSTICS n = ROW_COUNT;
    RAISE NOTICE 'imported % rows into %', n, t;
    RETURN n;
END
$fn$;

-- Parents before children.
SELECT pg_temp.copy_table('user');
SELECT pg_temp.copy_table('club');
SELECT pg_temp.copy_table('club_facility');
SELECT pg_temp.copy_table('user_ledger');
SELECT pg_temp.copy_table('refresh_tokens');
SELECT pg_temp.copy_table('user_device');
SELECT pg_temp.copy_table('email_verification');
SELECT pg_temp.copy_table('deletion_request');
SELECT pg_temp.copy_table('beta_request');
SELECT pg_temp.copy_table('social_account_connection');

-- refresh_tokens.id is the only integer key: move its sequence past the imported ids.
DO $$
DECLARE seq text := coalesce(pg_get_serial_sequence('public.refresh_tokens', 'id'),
                             to_regclass('public.refresh_tokens_id_seq')::text);
BEGIN
    IF seq IS NOT NULL THEN
        PERFORM setval(seq, greatest((SELECT coalesce(max(id), 0) FROM public.refresh_tokens), 1));
    END IF;
END $$;

COMMIT;
