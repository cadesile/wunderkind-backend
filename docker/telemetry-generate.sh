#!/bin/sh
# Recomputes the 24h pyramid-activity aggregate (fixtures simulated, capital
# deployed) shown on the landing page's "Chairman's Terminal" widget, by
# scanning recent SyncRecord.payload JSON. Cached into LiveTelemetrySnapshot
# so the landing page never scans that JSON on a page load.

CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
# memory_limit here is only a floor: LiveTelemetryService::refresh() raises it to its
# own REFRESH_MEMORY_LIMIT_BYTES for the aggregation itself, so that constant — not this
# number — is what to change if the 24h window outgrows its memory again. This is also
# why the admin rebuild button, which never reads this script, gets the same headroom.
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== telemetry-generate start ==="
if $PHP "$CONSOLE" app:telemetry:generate; then
    log "=== telemetry-generate done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
