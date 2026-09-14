#!/bin/sh
# Posts the next stat category (round-robin, per-period cursor) to all active
# social connections, for the period tier passed as $1.

CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
PHP="su-exec www-data php -d memory_limit=128M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== post-community-stat ($1) start ==="
if $PHP "$CONSOLE" app:post-community-stat "$1"; then
    log "=== post-community-stat ($1) done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
