#!/bin/sh
# Drains the `worldpackset` Messenger transport — the world pack set top-ups dispatched by
# POST /api/initialize/starter (one per game initialisation) and by app:worldpack:maintain.
#
# Its own consumer, not messenger-consume.sh and not worldpack-consume.sh, matching the
# transport split in config/packages/messenger.yaml. The reason is sharper here than for the
# other queues: ONE of these messages builds a whole country — eight tiers of NPC squads,
# measured at 8s for an 8-club country and 18s for an 18-club one — and they arrive at signup
# rate. Sharing the `worldpack` queue would park a handful of new players in front of an admin
# sitting and waiting on a country regenerate; sharing `async` would delay push notifications
# behind world generation.
#
# --limit is low on purpose. Each message is tens of seconds, so a high limit would hold one
# worker for many minutes and make --time-limit meaningless; the per-minute cron picks the rest
# up on the next tick, and the handler re-reads each country's deficit, so anything that became
# unnecessary in the meantime costs one cheap no-op rather than a wasted country generation.
#
# memory_limit is generous for the same reason worldpack-consume.sh's is: buildTierPack()
# materialises a league's entire squad set in memory before serialising it.
#
# ⚠️ NOT `-q`, and this is load-bearing. It used to be, alone among the consumers, and the one
# queue whose message means "build a whole country" was therefore the one queue that drained in
# silence: a handler that threw, or a PHP fatal that never reached the failure transport at
# all, left no line in this log. Measured 2026-11-21: 1,414 lines covering 23 hours, all of
# them start/done pairs, with no channel for anything in between. The output is two or three
# lines a run; the trade was never worth it.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/worldpackset-consume.lock
PHP="su-exec www-data php -d memory_limit=512M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

# flock is load-bearing, not belt-and-braces: without it a build that outruns the minute would
# have a second copy stacked on it every minute, and each copy is a full country generation.
exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== worldpackset-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== worldpackset-consume start ==="
if $PHP "$CONSOLE" messenger:consume worldpackset --time-limit=50 --limit=3 --no-interaction; then
    log "=== worldpackset-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
