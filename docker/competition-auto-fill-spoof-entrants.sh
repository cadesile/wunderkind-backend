#!/bin/sh
# Fills every REGISTERING instance whose template has opted into auto-fill
# (CompetitionTemplate::$autoFillSpoofEntrants) and is past its configured delay
# with spoof entrants. Runs every minute (see the Dockerfile crontab) — the finest
# configurable delay is 5 minutes, so this needs tighter precision than the other
# 5/10-min-cadence competition cron jobs.
#
# A flock guard is defense-in-depth alongside spoofAllEntrants()'s own registration
# transaction (which serializes concurrent last-slot fills) against crond starting a
# new instance of this script before the prior one has exited.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/competition-auto-fill-spoof-entrants.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== competition-auto-fill-spoof-entrants: previous run still in progress, skipping ==="
    exit 0
fi

log "=== competition-auto-fill-spoof-entrants start ==="
if $PHP "$CONSOLE" app:competition:auto-fill-spoof-entrants; then
    log "=== competition-auto-fill-spoof-entrants done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
