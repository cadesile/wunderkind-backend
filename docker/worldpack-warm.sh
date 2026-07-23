#!/bin/sh
# Rebuild the worldpack cache for every supported country + tier combination.
# Each invocation processes exactly one tier so PHP processes stay small and
# bounded — no risk of memory exhaustion or execution-time timeouts.
# Runs via crond every 6 hours inside the app container.

COUNTRIES="EN IT DE ES BR AR NL FR PT NG GH JP KR SE DK IE CI SN CN"
MAX_TIERS=8
CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

failed=0

log "=== worldpack-warm start ==="
for country in $COUNTRIES; do
    for tier in $(seq 1 $MAX_TIERS); do
        $PHP "$CONSOLE" app:worldpack:warm "$country" --tier "$tier" \
            || { log "  FAILED: $country/T$tier (exit $?)"; failed=$((failed + 1)); }
    done
done

if [ "$failed" -gt 0 ]; then
    log "=== worldpack-warm done with $failed failure(s) ==="
    exit 1
fi
log "=== worldpack-warm done ==="
