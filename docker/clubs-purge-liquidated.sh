#!/bin/sh
# Deletes clubs liquidated at game over once their delete_after date has passed
# (app:clubs:purge-liquidated). The wait is game_config.liquidated_club_delete_after_days.
# Owners keep their plots: liquidation detached the plot claim before this ever runs.
#
# Daily at 04:20 UTC, after the dormancy passes. Safe to re-run: a deleted club is gone and
# the next run simply finds fewer.

CONSOLE=/var/www/html/bin/console
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== clubs-purge-liquidated start ==="
if $PHP "$CONSOLE" app:clubs:purge-liquidated; then
    log "=== clubs-purge-liquidated done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
