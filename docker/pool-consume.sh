#!/bin/sh
# Drains the `pool` (Doctrine) Messenger transport — starter-pool top-up chunks queued by
# starter-pool-audit.sh (TopUpStarterPoolMessageHandler). Deliberately separate from
# worldpack-consume.sh and messenger-consume.sh: a sweep across every enabled country can
# queue hundreds of generation chunks, and sharing either queue would put a routine
# overnight top-up in front of an admin waiting on a country regenerate, or in front of a
# push send. Runs every minute (see the Dockerfile crontab); --time-limit bounds each run
# so it exits well before the next tick.
#
# memory_limit matches worldpack-consume.sh rather than messenger-consume.sh: this is the
# same entity-generation work, just chunked. --limit is low on purpose — each message
# generates up to StarterPoolAuditService::DEFAULT_CHUNK (100) players with their
# guardians, so a run of 20 is already a few thousand rows.
#
# flock guards against crond stacking a second consumer on top of one still mid-chunk —
# same pattern as worldpack-consume.sh.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/pool-consume.lock
PHP="su-exec www-data php -d memory_limit=512M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== pool-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== pool-consume start ==="
if $PHP "$CONSOLE" messenger:consume pool --time-limit=50 --limit=20 --no-interaction; then
    log "=== pool-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
