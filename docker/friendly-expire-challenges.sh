#!/bin/sh
# Expires every OPEN friendly challenge past its expiresAt and REFUNDS THE CHALLENGER'S
# ESCROWED STAKE (app:friendly:expire-challenges), with an inbox message and a push telling
# the owner nobody took the challenge.
#
# This job moves real money, and the guard against paying twice is the claim itself: the
# status flip is a conditional UPDATE (`status = 'expired' WHERE status = 'open'`) and the
# refund runs only when that UPDATE actually affected a row. So a re-run after a failure, or
# an overlapping tick, refunds nothing further — safe to re-run.
#
# Every minute, matching friendly-resolve-matches.sh, even though expiresAt is set in whole
# hours and nothing downstream is minute-sensitive. Lateness here cannot cause a wrong
# outcome: the accept path rejects a stale challenge on `expiresAt <= now` rather than on
# status, so an unexpired-looking row can never be accepted past its deadline. A late tick
# only delays the refund and its notification. The scan is indexed and normally empty.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/friendly-expire-challenges.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== friendly-expire-challenges: previous run still in progress, skipping ==="
    exit 0
fi

log "=== friendly-expire-challenges start ==="
if $PHP "$CONSOLE" app:friendly:expire-challenges; then
    log "=== friendly-expire-challenges done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
