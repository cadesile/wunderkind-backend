-- Sizing counts for the go-live migration. Read-only; aggregates only, nothing personal.
-- Works on both the master (prod) schema and the development schema.
\pset footer off
\echo 'Accounts and clubs'
SELECT
    (SELECT count(*) FROM "user")                                                  AS users,
    (SELECT count(*) FROM "user" WHERE email LIKE '%@spoof.buildmyclub.local')     AS spoof_users,
    (SELECT count(*) FROM club)                                                    AS clubs,
    (SELECT count(*) FROM club WHERE coalesce(is_spoof, false))                    AS spoof_clubs,
    (SELECT count(*) FROM (SELECT user_id FROM club WHERE NOT coalesce(is_spoof, false)
                           GROUP BY user_id HAVING count(*) > 1) x)                AS users_with_several_clubs,
    (SELECT count(*) FROM "user" u WHERE NOT EXISTS (SELECT 1 FROM club c WHERE c.user_id = u.id)) AS users_without_club,
    (SELECT count(*) FROM "user" WHERE appearance IS NOT NULL)                     AS users_with_avatar,
    (SELECT count(*) FROM "user" WHERE last_login_at > now() - interval '30 days') AS active_30d;

\echo 'Real clubs: money the buyout starts from (pence)'
SELECT
    count(*)                                         AS real_clubs,
    sum(balance)                                     AS balance_sum,
    min(balance)                                     AS balance_min,
    max(balance)                                     AS balance_max,
    count(*) FILTER (WHERE balance < 0)              AS negative_balance,
    max(reputation)                                  AS reputation_max,
    (SELECT count(*) FROM club_facility f JOIN club c2 ON c2.id = f.club_id
      WHERE NOT coalesce(c2.is_spoof, false))        AS facility_rows
FROM club WHERE NOT coalesce(is_spoof, false);

\echo 'Owner ledgers (pence)'
SELECT
    count(*)                         AS ledger_rows,
    count(DISTINCT user_id)          AS users_with_ledger,
    (SELECT sum(balance_after_pence) FROM (
        SELECT DISTINCT ON (user_id) balance_after_pence
          FROM user_ledger ORDER BY user_id, recorded_at DESC, id DESC) b) AS current_balance_sum,
    (SELECT max(balance_after_pence) FROM (
        SELECT DISTINCT ON (user_id) balance_after_pence
          FROM user_ledger ORDER BY user_id, recorded_at DESC, id DESC) b) AS current_balance_max
FROM user_ledger;

\echo 'Ledger rows by type'
SELECT type, count(*) AS rows, sum(amount_pence) AS amount_sum FROM user_ledger GROUP BY type ORDER BY 2 DESC;

\echo 'Other account tables'
SELECT
    (SELECT count(*) FROM refresh_tokens)            AS refresh_tokens,
    (SELECT count(*) FROM user_device)               AS user_devices,
    (SELECT count(*) FROM email_verification)        AS email_verifications,
    (SELECT count(*) FROM deletion_request)          AS deletion_requests,
    (SELECT count(*) FROM beta_request)              AS beta_requests,
    (SELECT count(*) FROM social_account_connection) AS social_connections,
    (SELECT count(*) FROM sync_record)               AS sync_records_not_imported;

\echo 'Schema version'
SELECT max(version) AS latest_migration, count(*) AS migrations FROM doctrine_migration_versions;
