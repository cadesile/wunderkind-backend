#!/bin/sh
# Drains the async (Doctrine) Messenger transport — push notifications
# (PushNotificationService) and admin-broadcast audience resolution
# (ResolveAdminMessageAudienceForPushMessageHandler). Runs every minute (see the
# Dockerfile crontab); --time-limit bounds each run so it exits well before the
# next tick even under a slow FCM multicast call.
#
# flock guards against crond stacking a second consumer on top of one that's
# still flushing a batch when a run overruns --time-limit — see
# competition-process-rounds.sh for the same pattern.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/messenger-consume.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== messenger-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== messenger-consume start ==="
if $PHP "$CONSOLE" messenger:consume async --time-limit=50 --limit=200 --no-interaction; then
    log "=== messenger-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
