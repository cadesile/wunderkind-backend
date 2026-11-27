#!/bin/sh
# Seats NPC clubs on land plots (app:npc-clubs:seat-plots), every country that has an unseated
# club. The safety net behind the seat that enrichment now does when it prices a town: a club
# whose town was priced some other way (admin edit, import, provision-plots) is seated here.
#
# Idempotent: a seated club is never moved and a town never gets more than its NPC cap, so a
# second run seats nothing. Releases seats on retired plots first. Not money, no flock needed.
#
# Scheduled at 03:50 UTC, clear of plots-dormancy-warn (03:40) and -reclaim (04:00).

CONSOLE=/var/www/html/bin/console
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== npc-seat-plots start ==="
if $PHP "$CONSOLE" app:npc-clubs:seat-plots; then
    log "=== npc-seat-plots done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
