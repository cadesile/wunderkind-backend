#!/bin/sh
# Runs app:worldpack:maintain — tops every LIVE country up to its world pack set target and
# prunes the tier payloads of sets claimed longer ago than the configured retention.
#
# This is a backstop, not the primary mechanism. Every game initialisation already dispatches
# a top-up for its own country; but that dispatch is deliberately best-effort, wrapped so a
# transport failure can never turn a successful initialisation into an error response. Without
# this sweep a lost message is a country quietly one set short with nothing to notice it.
#
# It is also what brings the system to target in three cases the per-init dispatch cannot:
# after a deploy, after a WORLD_PACK_VERSION bump (which makes every existing set unclaimable
# at once, so every country needs rebuilding), and after any window where generation was
# failing.
#
# QUEUES rather than generates, so one slow country cannot stall the sweep across the others —
# worldpackset-consume.sh does the work. Hourly: a country only loses a set when somebody
# starts a game there, and the per-init dispatch already covers the common case within the
# minute.
#
# Offset off the hour to avoid the :00 cluster, and off :35 where starter-pool-audit sits —
# that one queues the POOL work this command's generation depends on, so they should not
# contend.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/worldpack-maintain.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== worldpack-maintain: previous run still in progress, skipping ==="
    exit 0
fi

log "=== worldpack-maintain start ==="
if $PHP "$CONSOLE" app:worldpack:maintain --no-interaction; then
    log "=== worldpack-maintain done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
