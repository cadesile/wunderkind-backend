#!/bin/sh
# Drains the `clubsale` (Doctrine) Messenger transport — SellClubMessage, each of which credits
# real account money and then IRREVERSIBLY deletes a player's club and all of its history.
#
# Its own transport and its own consumer because of BLAST RADIUS, not throughput. Every other
# consumer in this directory drains work that can be re-run harmlessly; this one cannot. Two
# operational properties follow from the separation, and both are the point:
#
#   - A stuck or failed buyout shows up alone in `messenger:failed:show` instead of mixed into
#     push-notification retries, so nobody has to find it.
#   - Commenting out this script's crontab line pauses EXACTLY the destructive work and nothing
#     else. That is the control an operator wants mid-batch at go-live, and it does not exist on
#     a shared queue.
#
# --limit IS LOW ON PURPOSE, like enrichment-consume.sh's, but bounding damage rather than spend.
# A go-live batch is thousands of sales; draining them a few per minute means a defect found on
# sale five is a five-club problem, and the cron cadence is the rate limiter. Raise it only
# deliberately, after a batch has been observed working.
#
# --time-limit bounds each run so it exits before the next tick. flock guards against crond
# stacking a second consumer on top of one still mid-scrub — which matters more here than
# anywhere else, because two consumers racing the same sale rely entirely on the ledger's
# idempotency guard to avoid a double payout. That guard holds (append() takes a per-user
# advisory lock and pre-checks the source ref), but it should not be the only thing standing
# between a duplicate delivery and somebody's money.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/clubsale-consume.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== clubsale-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== clubsale-consume start ==="
if $PHP "$CONSOLE" messenger:consume clubsale --time-limit=50 --limit=10 --no-interaction; then
    log "=== clubsale-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
