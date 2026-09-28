#!/bin/sh
# Ensures every active CompetitionTemplate has an open (REGISTERING) instance.
# Not time-precision sensitive — a new registration slot appearing a few
# minutes late is a minor UX gap, not a correctness bug — so this runs on the
# low-frequency cron tier alongside leaderboards-generate.

CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
PHP="su-exec www-data php -d memory_limit=128M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== competition-provision-instances start ==="
if $PHP "$CONSOLE" app:competition:provision-instances; then
    log "=== competition-provision-instances done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
