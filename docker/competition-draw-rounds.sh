#!/bin/sh
# QUEUES every CompetitionRound whose scheduledAt is due, one message each onto the
# `competition` transport — it no longer draws them. Rounds must fire near their scheduled
# timestamp, so this runs every minute (see the Dockerfile crontab), and the tick is now
# cheap regardless of how many tournaments are running: an indexed, normally-empty due-row
# scan plus one INSERT per due round. The drawing itself happens on competition-consume.sh.
#
# The split exists because a draw is not cheap — it writes an inbox message per entrant and
# a push per fixture — and doing that for every due round inline, in one process, is what
# would not hold with several tournaments rolling at once.
#
# A flock guard is defense-in-depth alongside the DB-level claim lease
# (CompetitionDrawService::claimDraw) against crond starting a new instance of this script
# before the prior one has exited; the lease alone is sufficient for correctness, this just
# avoids piling up redundant processes.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/competition-draw-rounds.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== competition-draw-rounds: previous run still in progress, skipping ==="
    exit 0
fi

log "=== competition-draw-rounds start ==="
if $PHP "$CONSOLE" app:competition:draw-rounds; then
    log "=== competition-draw-rounds done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
