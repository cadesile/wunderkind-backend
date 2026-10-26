#!/bin/sh
# Runs app:starter-pool:audit — checks every enabled country's starter-pack supply and
# queues pool top-up chunks onto the `pool` transport for any that fall short. Queues
# only; the generating is done by pool-consume.sh, so this script is short and cheap
# however much work it finds.
#
# Hourly, not per-minute. The pool drains at the rate clubs are created, which is slow,
# and the command's own guard refuses to sweep while the previous sweep's chunks are
# still undrained — so a faster cron would mostly log "skipping". Hourly also means a
# country that goes short after a burst of sign-ups is servable again within the hour
# without an admin noticing first.
#
# Offset off the hour so it does not land on the same tick as the :00 cluster
# (spotlight-generate at 0 */3).
#
# flock is belt-and-braces: the sweep is read-mostly and bounded, but a slow diagnostic
# across 25 countries on a loaded box should not get a second copy stacked on it.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/starter-pool-audit.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== starter-pool-audit: previous run still in progress, skipping ==="
    exit 0
fi

log "=== starter-pool-audit start ==="
if $PHP "$CONSOLE" app:starter-pool:audit --no-interaction; then
    log "=== starter-pool-audit done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
