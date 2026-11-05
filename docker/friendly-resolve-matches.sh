#!/bin/sh
# Resolves every ACCEPTED friendly challenge whose kickoffAt is due
# (app:friendly:resolve-matches): runs the match engine per due challenge, then either pays
# the winner 2x the stake as an acceptable inbox message or, on a draw, refunds both escrowed
# stakes directly to the two clubs' balances.
#
# Every minute because accept sets kickoffAt = now + 15 minutes, so a player is actively
# waiting on this result — the same reasoning as competition-resolve-rounds.sh. The tick is
# cheap when nothing is due: an indexed scan for ACCEPTED rows with a past kickoffAt.
#
# Re-entry is safe at the DB level. FriendlyChallengeService::claimResolution() is a
# conditional UPDATE on `resolve_locked_at IS NULL AND status = 'accepted'`, so an overlapping
# tick claims nothing and cannot double-resolve a match or pay a stake twice.
#
# BUT the flock below is load-bearing here, not just defense-in-depth as it is on the
# competition side, because that claim has no lease reclaim. CompetitionResultsService
# ::claimResults() claims on `(resolve_locked_at IS NULL OR resolve_locked_at < :reclaimBefore)`;
# this one has only the `IS NULL` half. So a run that dies *after* claiming but before
# recording the result strands that challenge permanently: its status is still ACCEPTED, so
# findDueForResolution() keeps returning it, but claimResolution() rejects it on every later
# tick — and both clubs' stakes stay escrowed. A challenge showing "Resolve claimed" in admin
# with a status still `accepted` is exactly that state, and needs the column cleared by hand.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/friendly-resolve-matches.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== friendly-resolve-matches: previous run still in progress, skipping ==="
    exit 0
fi

log "=== friendly-resolve-matches start ==="
if $PHP "$CONSOLE" app:friendly:resolve-matches; then
    log "=== friendly-resolve-matches done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
