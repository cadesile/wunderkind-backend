#!/bin/sh
# Runs app:leaderboards:close-periods — for every enabled LeaderboardAwardDefinition whose
# configured period has ended, freezes that period's board into an immutable archive, pays
# the configured places as inbox prizes, and prunes the live leaderboard_entry rows for it.
#
# THIS ENTRY'S OWN SCHEDULE IS JUST THE TICK GRANULARITY, NOT THE AWARD CADENCE. The
# cadence (weekly / monthly / quarterly) is per-definition and configured at
# /admin -> Sync & Leaderboards -> Award Definitions. Same relationship
# post-community-stat-tick has with its admin-editable posting schedule.
#
# Every 10 minutes, not every minute. A period boundary is a calendar instant and nothing
# downstream is minute-sensitive — the prize is inbox mail the player reads whenever — and
# staying off the 5-minute leaderboards-generate tick means the two never contend on the
# same rows in the same minute. Offset to :04 so it lands on no other job's minute
# (*/5 -> 0,5,10...; */15 -> 0,15,30,45; */10 -> 0,10,20...; 0 */3; 20 3; 35 *).
#
# flock, unlike reputation-decay.sh (whose own header documents why a DAILY job needs
# none): this is sub-hourly, and a slow close over a large club table is exactly the shape
# that stacks. The archive's own claim already makes re-entry harmless — a second process
# loses the ON CONFLICT and returns — so flock is the cheap outer guard, not the
# correctness mechanism.
#
# A period left at status `pending` past its 300s claim lease is the signal that something
# is failing mid-close: the archive is incomplete and the live rows for it are deliberately
# NOT pruned. Check this log, and /admin -> Period Archives.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/leaderboards-close-periods.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== leaderboards-close-periods: previous run still in progress, skipping ==="
    exit 0
fi

log "=== leaderboards-close-periods start ==="
if $PHP "$CONSOLE" app:leaderboards:close-periods --no-interaction; then
    log "=== leaderboards-close-periods done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
