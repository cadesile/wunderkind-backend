#!/bin/sh
# Drains the `enrichment` (Doctrine) Messenger transport — EnrichLocationMessage, each of which
# makes ONE billed call to an external AI provider to fill in a town's coordinates, population
# and land value.
#
# Its own transport and its own consumer, deliberately. An admin "enrich all remaining" sweep
# queues one message per un-enriched location — hundreds of them — each with the unpredictable
# latency of a third-party API. Sharing a queue would put that overnight sweep in front of
# `competition` (which is latency-sensitive against wall-clock match times), `async` (push
# sends), `pool`, or `worldpack`.
#
# --limit IS LOW ON PURPOSE AND IS A COST CONTROL, not just a memory one: every message spends
# money. A modest per-tick ceiling means a misconfiguration or a runaway sweep is bounded by the
# cron cadence rather than draining the budget in one run. --time-limit bounds each run so it
# exits before the next tick.
#
# memory_limit matches messenger-consume.sh rather than the generation consumers: the work here
# is one HTTP request and a handful of row writes, not entity generation.
#
# flock guards against crond stacking a second consumer on top of one still waiting on the
# provider — the same pattern as worldpack-consume.sh, and it matters more here, because a
# stacked consumer would double the spend rate.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/enrichment-consume.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== enrichment-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== enrichment-consume start ==="
if $PHP "$CONSOLE" messenger:consume enrichment --time-limit=50 --limit=10 --no-interaction; then
    log "=== enrichment-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
