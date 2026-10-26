#!/bin/sh
# Picks a new landing page "Club Spotlight" from the 5 most active clubs in the
# trailing 12h window and notifies its owner (in-game inbox + push). Runs every
# 3h: the 12h here is the lookback deciding who is eligible, not how long a pick
# stays up — that is ClubSpotlightService::SPOTLIGHT_DURATION_HOURS, which this
# cron's cadence has to match. Cached
# into ClubSpotlight so the landing page never queries Club at page-load time.

CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== spotlight-generate start ==="
if $PHP "$CONSOLE" app:spotlight:generate; then
    log "=== spotlight-generate done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
