#!/bin/sh
# Pre-warms the player, staff and scout pool for every supported country,
# plus the international foreign-slot pool.
# Runs via crond 30 minutes before worldpack-warm.sh so entities are
# available when worldpack cache generation consumes them.
# Each country is a separate PHP process to keep memory bounded.

COUNTRIES="EN IT DE ES BR AR NL FR PT NG GH JP KR SE DK IE CI SN CN"
CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

failed=0

log "=== pool-warm start ==="
for country in $COUNTRIES; do
    $PHP "$CONSOLE" app:pool:warm "$country" \
        || { log "  FAILED: $country (exit $?)"; failed=$((failed + 1)); }
done

$PHP "$CONSOLE" app:pool:warm --international \
    || { log "  FAILED: international pool (exit $?)"; failed=$((failed + 1)); }

if [ "$failed" -gt 0 ]; then
    log "=== pool-warm done with $failed failure(s) ==="
    exit 1
fi
log "=== pool-warm done ==="
