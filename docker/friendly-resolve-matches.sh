#!/bin/sh
# QUEUES every ACCEPTED friendly challenge whose kickoffAt is due, one message each onto the
# `friendly` transport (app:friendly:resolve-matches) — it no longer resolves them. The
# resolving happens on friendly-consume.sh.
#
# Every minute because accept sets kickoffAt = now + 15 minutes, so a player is actively
# waiting on this result — the same reasoning as competition-resolve-rounds.sh. The tick is
# cheap regardless of how many friendlies are in flight: an indexed, normally-empty due-row
# scan (idx_friendly_challenge_status_kickoff) plus one INSERT per due challenge.
#
# That split is the point. Resolving a friendly runs the match engine and narrative
# generation for a full 90 minutes, and this tick used to do that for every due challenge
# inline, in a single 256M process. How many friendlies kick off in the same minute is
# decided by players rather than by a schedule, so that was unbounded work in one tick on a
# busy evening.
#
# Dispatch is not deduped, deliberately: the message carries a challenge id only and the
# handler takes the claim, so a duplicate is a no-op. A challenge queued but not yet drained
# is still ACCEPTED and still due, so the next tick queues it again — intended, not a leak.
#
# flock is defense-in-depth alongside the DB-level claim lease
# (FriendlyChallenge::CLAIM_LEASE_SECONDS, taken in the handler) against crond starting a
# new instance before the prior one has exited; the lease alone is sufficient for
# correctness, this just avoids piling up redundant processes.

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
