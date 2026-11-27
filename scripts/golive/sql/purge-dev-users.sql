-- Go-live step: clear dev's own players out of wk_golive (a copy of dev), keeping everything
-- else dev has built: config, economy, countries, locations, plots, NPC clubs, enriched data,
-- unclaimed world pack buffers, competition and leaderboard DEFINITIONS, admins.
--
-- RUN ONLY ON wk_golive. The wrapper (import.sh) refuses any other database, and the guard
-- below refuses too.
--
-- Why SQL and not AccountDeletionService: this removes EVERY player row, not a chosen few,
-- so it is a list of runtime tables to empty rather than a graph to walk per account. The
-- TRUNCATE has no CASCADE on purpose: if a table outside this list references one inside it,
-- Postgres refuses the statement instead of silently emptying the outside table too. That
-- refusal is the review step for any table added after this script was written.
--
-- One transaction: it either all happens or none of it does.

\set ON_ERROR_STOP on
BEGIN;

DO $$ BEGIN
    IF current_database() <> 'wk_golive' THEN
        RAISE EXCEPTION 'purge-dev-users.sql must only run on wk_golive, not %', current_database();
    END IF;
END $$;

-- 1. World pack sets claimed by a dev club. A claimed set belongs to one save forever and is
--    never served again, so once its club is gone it is dead weight. CASCADE through set_id
--    removes its cached tiers and tier-run rows. Unclaimed READY/BUILDING buffers stay.
DELETE FROM world_pack_set
 WHERE claimed_by_club_id IS NOT NULL OR status = 'claimed';

-- 2. Player runtime data. Definitions (competition_template, reward_template,
--    leaderboard_award_definition/placement, audience_group, admin broadcasts) are kept.
TRUNCATE TABLE
    -- competitions and friendlies: instances, entries, fixtures, results
    active_competition, competition_round, competition_entrant, competition_entrant_squad,
    competition_entrant_submission, competition_fixture, competition_result,
    competition_result_audit, competition_response_cache, entrant_reward_claim,
    friendly_challenge, friendly_challenge_squad, friendly_challenge_submission,
    friendly_challenge_audit,
    -- per-club history
    sync_record, player_career_stat_snapshot, player_career_stat, staff_career_profile,
    match_result, season_record, season_snapshot, season_ratings_snapshot, transfer,
    inbox_message, leaderboard_entry, leaderboard_period_archive,
    leaderboard_period_archive_entry, club_spotlight, club_dormancy_warning, club_facility,
    audience_group_member,
    -- land: claims, holds and their valuations (no claim = the plot is free again)
    plot_claim_valuation, plot_claim, plot_hold, club_sale,
    -- accounts
    user_ledger, user_device, email_verification, message_delivery, setup_quiz_answer,
    refresh_tokens, idempotency_record,
    -- replaced wholesale by production's copies in the import
    deletion_request, beta_request, social_account_connection;

-- 3. Pool rows (sponsors, investors) assigned to a dev club go back to unassigned.
UPDATE sponsor  SET club_id = NULL WHERE club_id IS NOT NULL;
UPDATE investor SET club_id = NULL WHERE club_id IS NOT NULL;

-- 4. Admin messages aimed at one dev club (broadcasts have no target and stay).
DELETE FROM admin_message WHERE target_club_id IS NOT NULL;

-- 5. The clubs and accounts themselves.
DELETE FROM club;
DELETE FROM "user";

-- Nothing left that a later import could collide with.
DO $$
DECLARE n bigint;
BEGIN
    SELECT (SELECT count(*) FROM "user") + (SELECT count(*) FROM club)
         + (SELECT count(*) FROM plot_claim) + (SELECT count(*) FROM user_ledger) INTO n;
    IF n <> 0 THEN
        RAISE EXCEPTION 'purge left % player rows behind', n;
    END IF;
END $$;

COMMIT;
