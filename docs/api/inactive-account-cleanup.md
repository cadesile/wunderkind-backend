# Inactive account cleanup

`app:users:prune-inactive` deletes accounts that **never played**, after they have been unseen for a while and were warned by email. Cron: daily 04:40 UTC (`docker/users-prune-inactive.sh`, log `/var/log/users-prune-inactive-cron.log`).

## Who is a candidate (all must hold)

- Unseen for `inactiveAccountPruneDays` (default 90). Last seen = latest of sign-up, last login, any `user_device.last_active_at`, and the newest refresh token's issue time.
- No club, no `club_sale`, no `leaderboard_period_archive_entry`: never had a club.
- No `user_ledger` rows: any owner credit or history protects the account (go-live buyout users included).
- No `plot_claim` or `plot_hold`, in any status.
- Not an admin role, not `debugLoggingEnabled`, not a spoof account, not in the protected email list.

Anyone with any club or sync history is never touched, however long they have been away.

## Flow

1. First run that finds a candidate emails them and writes an `inactive_account_warning` row (`delete_after` = now + `inactiveAccountPruneWarningDays`, default 14). The email is sent before the row is written, so a failed send cannot lead to an unwarned delete.
2. Being seen again (login, device check-in, new token) clears the warning; a later lapse starts over.
3. Once `delete_after` passes and the account is still unseen, it is deleted through `AccountDeletionService::deleteAccountsBulk()` and a `deletion_request` row with status `pruned_inactive` is written (purged after the usual retention by `app:deletion-requests:purge`).

## Controls

- Game Config > Inbox & notification frequencies > **Inactive account cleanup**: `off` (default) / `dry_run` / `live`. The cron passes `--live`, so this switch decides per database.
- Without `--live` the command is always a dry run: `bin/console app:users:prune-inactive`.
- `inactiveAccountPruneMaxPerRun` (default 50) caps warnings and deletions per run; 0 pauses both.
- A live run refuses to act when the pool exceeds `--abort-above` (default 1000).
- Protected emails: one per line in Game Config.
