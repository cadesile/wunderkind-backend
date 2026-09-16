#!/bin/sh
# Executes every CompetitionRound whose scheduledAt is due. Matches must fire
# near their scheduled timestamp, so this runs every minute (see the Dockerfile
# crontab) — cheap since the due-row query is an indexed, normally-empty scan.
#
# A flock guard is defense-in-depth alongside the DB-level claim-lock
# (CompetitionRoundProcessorService::claimRound) against crond starting a new
# instance of this script before the prior one has exited; the DB lock alone
# is sufficient for correctness, this just avoids piling up redundant processes.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/competition-process-rounds.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== competition-process-rounds: previous run still in progress, skipping ==="
    exit 0
fi

log "=== competition-process-rounds start ==="
if $PHP "$CONSOLE" app:competition:process-rounds; then
    log "=== competition-process-rounds done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
