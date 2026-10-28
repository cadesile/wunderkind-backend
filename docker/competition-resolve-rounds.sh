#!/bin/sh
# QUEUES every CompetitionRound whose matchesResolveAt is due, one message each onto the
# `competition` transport — it no longer resolves them. Results must land near their
# scheduled timestamp, so this runs every minute (see the Dockerfile crontab), and the tick
# is now cheap regardless of how many tournaments are running: an indexed, normally-empty
# due-row scan plus one INSERT per due round. The resolving happens on
# competition-consume.sh.
#
# This is the split that mattered most. Resolving one round runs the match engine and
# narrative generation for every fixture in it — up to 32 — and this tick used to do that
# for every due round inline, in a single process with a 256M limit. Several tournaments
# reaching a round boundary in the same minute was unbounded work in one tick.
#
# A flock guard is defense-in-depth alongside the DB-level claim lease
# (CompetitionResultsService::claimResults) against crond starting a new instance of this
# script before the prior one has exited; the lease alone is sufficient for correctness,
# this just avoids piling up redundant processes.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/competition-resolve-rounds.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== competition-resolve-rounds: previous run still in progress, skipping ==="
    exit 0
fi

log "=== competition-resolve-rounds start ==="
if $PHP "$CONSOLE" app:competition:resolve-rounds; then
    log "=== competition-resolve-rounds done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
