#!/bin/sh
# Rebuild the worldpack cache for every supported country.
# Runs via crond every 6 hours inside the app container.

COUNTRIES="EN IT DE ES BR AR NL FR PT NG GH JP KR SE DK IE CI SN CN"
CONSOLE=/var/www/html/bin/console

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== worldpack-warm start ==="
for country in $COUNTRIES; do
    log "Warming $country ..."
    php "$CONSOLE" app:worldpack:warm "$country" --force \
        && log "  $country OK" \
        || log "  $country FAILED (exit $?)"
done
log "=== worldpack-warm done ==="
