#!/bin/sh
# Checks every period tier's admin-configured auto-post schedule (GameConfig,
# editable from /admin?routeName=admin_social_connections) and runs
# app:post-community-stat for any period whose interval has elapsed since it
# last ran. Replaces the old fixed per-period crontab entries — cadence is
# now runtime-configurable without a redeploy.

CONSOLE=/var/www/html/bin/console
PHP="su-exec www-data php -d memory_limit=128M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== post-community-stat-tick start ==="
if $PHP "$CONSOLE" app:post-community-stat-tick; then
    log "=== post-community-stat-tick done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
