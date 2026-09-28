#!/bin/sh
# Drains the `worldpack` (Doctrine) Messenger transport — World Pack Cache
# tier/club generation messages (WorldPackGenerationOrchestrator). Deliberately
# separate from messenger-consume.sh's `async` transport (push notifications):
# a country regenerate can queue 100+ club-generation messages, each doing real
# DB-heavy work, and sharing one queue meant that backlog (or a stuck/failing
# push send) delayed the other. Runs every minute (see the Dockerfile crontab);
# --time-limit bounds each run so it exits well before the next tick even under
# a slow generation batch. Higher memory_limit than messenger-consume.sh since
# club generation is heavier per-message than building a push payload.
#
# flock guards against crond stacking a second consumer on top of one that's
# still flushing a batch when a run overruns --time-limit — see
# competition-draw-rounds.sh/competition-resolve-rounds.sh for the same pattern.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/worldpack-consume.lock
PHP="su-exec www-data php -d memory_limit=512M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== worldpack-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== worldpack-consume start ==="
if $PHP "$CONSOLE" messenger:consume worldpack --time-limit=50 --limit=200 --no-interaction; then
    log "=== worldpack-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
