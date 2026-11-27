#!/bin/sh
# Warns, then deletes, accounts that never played and have been unseen for the configured days
# (app:users:prune-inactive --live). See PruneInactiveAccountsCommand for the rules.
#
# --live is passed here on every environment, so THE GAME CONFIG SWITCH DECIDES what happens:
# "Inactive account cleanup" off = nothing, dry run = log only, live = warn and delete. It
# defaults to off, and dev and prod have separate databases, so stage can stay dry while prod
# is live from the same image. Commenting out the crontab line stops it completely.
#
# Idempotent and bounded: at most max-per-run warnings and deletions, a deletion needs a sent
# warning whose period has passed, and the command refuses to act on an unexpectedly large pool.
#
# Daily at 04:40 UTC, after the dormancy, seating and liquidated-club jobs.

CONSOLE=/var/www/html/bin/console
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== users-prune-inactive start ==="
if $PHP "$CONSOLE" app:users:prune-inactive --live --no-interaction; then
    log "=== users-prune-inactive done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
