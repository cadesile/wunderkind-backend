-- Go-live step: pick production's accounts out of wk_prod_stage (prod restored and migrated
-- to development's schema) into a separate schema, golive_export, ready to be dumped and
-- loaded into wk_golive.
--
-- What comes over, and why (agreed with Courtney 2026-11-26):
--   user, user_ledger (as is), refresh_tokens, user_device, email_verification: the account,
--     its money and its sign-in, so players stay logged in.
--   deletion_request, beta_request, social_account_connection: prod's records and the game's
--     own social accounts (their tokens are encrypted with the prod-only key).
--   club, club_facility: ONLY so the buyout can value and pay for each club. The buyout then
--     deletes them. Their league / location / world pack links are nulled, because those ids
--     belong to prod's world, not dev's.
-- Not imported: sync records, inbox, transfers, results, leaderboards, competitions; the
-- buyout would delete them anyway. The prod dump is the archive.
--
-- Spoof accounts (admin-generated test owners on the spoof domain who own only spoof clubs)
-- and spoof clubs are left behind.

\set ON_ERROR_STOP on
BEGIN;

DO $$ BEGIN
    IF current_database() <> 'wk_prod_stage' THEN
        RAISE EXCEPTION 'export-accounts.sql must only run on wk_prod_stage, not %', current_database();
    END IF;
END $$;

DROP SCHEMA IF EXISTS golive_export CASCADE;
CREATE SCHEMA golive_export;

CREATE TABLE golive_export."user" AS
SELECT u.* FROM public."user" u
 WHERE u.email NOT LIKE '%@spoof.buildmyclub.local'
    OR EXISTS (SELECT 1 FROM public.club c WHERE c.user_id = u.id AND NOT coalesce(c.is_spoof, false));

CREATE TABLE golive_export.club AS
SELECT c.* FROM public.club c
 WHERE NOT coalesce(c.is_spoof, false)
   AND c.user_id IN (SELECT id FROM golive_export."user");
UPDATE golive_export.club
   SET current_league_id = NULL, home_location_id = NULL, world_pack_set_id = NULL;

CREATE TABLE golive_export.club_facility AS
SELECT f.* FROM public.club_facility f WHERE f.club_id IN (SELECT id FROM golive_export.club);

-- The ledger comes over as it is (decision 1). Only the two links that would point at rows
-- not imported are cut: sync records are not imported, and a spoof club is not either.
CREATE TABLE golive_export.user_ledger AS
SELECT l.* FROM public.user_ledger l WHERE l.user_id IN (SELECT id FROM golive_export."user");
UPDATE golive_export.user_ledger SET source_sync_record_id = NULL;
UPDATE golive_export.user_ledger SET club_id = NULL
 WHERE club_id IS NOT NULL AND club_id NOT IN (SELECT id FROM golive_export.club);

CREATE TABLE golive_export.refresh_tokens AS
SELECT t.* FROM public.refresh_tokens t WHERE t.username IN (SELECT email FROM golive_export."user");

CREATE TABLE golive_export.user_device AS
SELECT d.* FROM public.user_device d WHERE d.user_id IN (SELECT id FROM golive_export."user");

CREATE TABLE golive_export.email_verification AS
SELECT e.* FROM public.email_verification e WHERE e.user_id IN (SELECT id FROM golive_export."user");

CREATE TABLE golive_export.deletion_request          AS SELECT * FROM public.deletion_request;
CREATE TABLE golive_export.beta_request              AS SELECT * FROM public.beta_request;
CREATE TABLE golive_export.social_account_connection AS SELECT * FROM public.social_account_connection;

-- What was picked, for the run log.
SELECT 'user' AS t, count(*) FROM golive_export."user"
UNION ALL SELECT 'club', count(*) FROM golive_export.club
UNION ALL SELECT 'club_facility', count(*) FROM golive_export.club_facility
UNION ALL SELECT 'user_ledger', count(*) FROM golive_export.user_ledger
UNION ALL SELECT 'refresh_tokens', count(*) FROM golive_export.refresh_tokens
UNION ALL SELECT 'user_device', count(*) FROM golive_export.user_device
UNION ALL SELECT 'email_verification', count(*) FROM golive_export.email_verification
UNION ALL SELECT 'deletion_request', count(*) FROM golive_export.deletion_request
UNION ALL SELECT 'beta_request', count(*) FROM golive_export.beta_request
UNION ALL SELECT 'social_account_connection', count(*) FROM golive_export.social_account_connection;

COMMIT;
