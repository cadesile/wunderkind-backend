#!/bin/sh
# Releases plots held by clubs inactive for 30 days and CREDITS THE OWNER
# (app:plots:dormancy-reclaim). The payout is land + half the stadium + the club's value,
# identical to what a voluntary sell-back pays.
#
# THIS JOB MOVES REAL MONEY, which is why two guards sit in front of it:
#
#   1. It refuses to reclaim a plot with no DELIVERED one-day warning for the current dormancy
#      cycle, and reports how many it skipped for that reason. So if plots-dormancy-warn.sh is
#      broken or behind, this job stalls visibly instead of quietly taking plots.
#   2. The release is a conditional UPDATE and the credit is idempotent on the claim id, so a
#      second run over the same claim pays nothing further. Safe to re-run after a failure.
#
# Scheduled at 04:00 UTC, twenty minutes after the warning pass, so a club that crosses the
# 29-day threshold today is warned before it is reclaimed tomorrow.
#
# Deliberately NOT guarded by flock, like reputation-decay.sh — daily cadence, and the
# conditional UPDATE makes re-entry harmless.
#
# Dry-run it before the first run on an environment that has never reclaimed: the first pass
# settles every dormant claim at once and touches far more than a nightly one.

CONSOLE=/var/www/html/bin/console
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== plots-dormancy-reclaim start ==="
if $PHP "$CONSOLE" app:plots:dormancy-reclaim; then
    log "=== plots-dormancy-reclaim done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
