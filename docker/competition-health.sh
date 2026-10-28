#!/bin/sh
# Reports whether competition round processing is keeping up, and nothing else — it is
# read-only, takes no locks and writes no rows, so it can never be the reason a round fails
# to process.
#
# This exists because every bound in the pipeline was set from app:competition:benchmark on
# one box: the 300s claim lease, competition-consume's --time-limit/--memory-limit, and the
# one-minute cron cadence the whole design assumes. Nothing in production said whether those
# settings still held, so a mis-tuning surfaced first as a player noticing a late draw. This
# tick is the thing that notices instead.
#
# Every 5 minutes, against a 180s overdue grace (three cron ticks): frequent enough that a
# stalled consumer is visible within one grace window, infrequent enough to be free. --strict
# exits 1 on any warning, so the failure is in the log as a FAILED line rather than buried in
# otherwise-identical output that nobody reads.
#
# The log is the whole interface — nothing pages anyone. That is deliberate for now: the check
# has no production baseline yet, so its thresholds are first guesses and alerting on a guess
# trains people to ignore it. Watch /var/log/competition-health-cron.log for a week before
# wiring it to anything that wakes someone up.

CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
PHP="su-exec www-data php -d memory_limit=128M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

if $PHP "$CONSOLE" app:competition:health --strict; then
    log "=== competition-health: keeping up ==="
else
    status=$?
    log "  FAILED (exit $status) — see the warnings above"
    exit "$status"
fi
