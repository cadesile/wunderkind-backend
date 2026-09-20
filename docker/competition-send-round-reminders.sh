#!/bin/sh
# Sends a "starting soon" push for every CompetitionRound due within its 15-minute
# reminder lead time. Runs every 5 minutes (see the Dockerfile crontab) — plenty of
# slack for the lead time, unlike competition-process-rounds' 1-minute cadence.
#
# A flock guard is defense-in-depth alongside the DB-level claim-lock
# (CompetitionRoundReminderService::claimReminder) against crond starting a new
# instance of this script before the prior one has exited; the DB lock alone is
# sufficient for correctness, this just avoids piling up redundant processes.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/competition-send-round-reminders.lock
PHP="su-exec www-data php -d memory_limit=128M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== competition-send-round-reminders: previous run still in progress, skipping ==="
    exit 0
fi

log "=== competition-send-round-reminders start ==="
if $PHP "$CONSOLE" app:competition:send-round-reminders; then
    log "=== competition-send-round-reminders done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
