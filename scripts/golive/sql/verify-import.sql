-- Go-live check after import-accounts.sql: did every picked row land, unchanged?
-- Exits non-zero (RAISE) on the first mismatch. Also stores each owner's balance before the
-- buyout in golive_export.balance_before, which verify-sale.sql compares against.

\set ON_ERROR_STOP on
BEGIN;

DO $$
DECLARE
    t text;
    a bigint;
    b bigint;
BEGIN
    IF current_database() <> 'wk_golive' THEN
        RAISE EXCEPTION 'verify-import.sql must only run on wk_golive';
    END IF;

    FOREACH t IN ARRAY ARRAY['user','club','club_facility','user_ledger','refresh_tokens',
                             'user_device','email_verification','deletion_request',
                             'beta_request','social_account_connection'] LOOP
        EXECUTE format('SELECT count(*) FROM golive_export.%I', t) INTO a;
        EXECUTE format('SELECT count(*) FROM public.%I', t) INTO b;
        IF a <> b THEN
            RAISE EXCEPTION 'row count mismatch on %: exported %, in wk_golive %', t, a, b;
        END IF;
        RAISE NOTICE 'ok  % rows  %', lpad(b::text, 8), t;
    END LOOP;

    -- Every owner's current balance (the latest ledger row, the rule UserLedgerRepository
    -- uses) must be what it was on prod.
    SELECT count(*) INTO a FROM (
        SELECT DISTINCT ON (user_id) user_id, balance_after_pence
          FROM golive_export.user_ledger ORDER BY user_id, recorded_at DESC, id DESC) e
     FULL JOIN (
        SELECT DISTINCT ON (user_id) user_id, balance_after_pence
          FROM public.user_ledger ORDER BY user_id, recorded_at DESC, id DESC) p USING (user_id)
     WHERE e.balance_after_pence IS DISTINCT FROM p.balance_after_pence;
    IF a <> 0 THEN
        RAISE EXCEPTION '% owners have a different balance after the import', a;
    END IF;

    -- Avatars and profiles came across byte for byte.
    SELECT count(*) INTO a FROM golive_export."user" e JOIN public."user" p USING (id)
     WHERE e.appearance::text IS DISTINCT FROM p.appearance::text
        OR e.name IS DISTINCT FROM p.name OR e.email <> p.email OR e.password <> p.password;
    IF a <> 0 THEN
        RAISE EXCEPTION '% users differ from prod after the import', a;
    END IF;

    -- Nothing imported points into dev's world yet, and no dev player survived the purge.
    SELECT count(*) INTO a FROM public.club
     WHERE current_league_id IS NOT NULL OR home_location_id IS NOT NULL OR world_pack_set_id IS NOT NULL;
    IF a <> 0 THEN RAISE EXCEPTION '% imported clubs still link to a league, location or pack', a; END IF;
    SELECT count(*) INTO a FROM public.plot_claim;
    IF a <> 0 THEN RAISE EXCEPTION '% plot claims exist before the buyout', a; END IF;
    SELECT count(*) INTO a FROM public.club WHERE user_id NOT IN (SELECT id FROM public."user");
    IF a <> 0 THEN RAISE EXCEPTION '% clubs without an owner', a; END IF;
END $$;

DROP TABLE IF EXISTS golive_export.balance_before;
CREATE TABLE golive_export.balance_before AS
SELECT u.id AS user_id,
       coalesce((SELECT l.balance_after_pence FROM public.user_ledger l WHERE l.user_id = u.id
                  ORDER BY l.recorded_at DESC, l.id DESC LIMIT 1), 0) AS balance_pence
  FROM public."user" u;

SELECT count(*) AS owners, sum(balance_pence) AS balance_sum_pence FROM golive_export.balance_before;
COMMIT;
