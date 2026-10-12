#!/bin/sh
# Charges every non-spoof club the reputation it owes for the days it has gone
# without syncing (5 points per whole day — ReputationDecayService::POINTS_PER_DAY).
#
# Runs once a day. The command is idempotent and catch-up-safe: each club carries
# its own decay anchor (Club::$reputationDecayedAt), so a second run on the same
# day costs nothing and a run of missed days is settled in a single pass. That is
# also why there is no separate backfill command — the first run on an environment
# that has never decayed settles every dormant club at once. Do a
# `app:reputation:decay --dry-run` before that first run; it is far larger than a
# nightly one.
#
# Deliberately NOT guarded by flock, unlike the minute-cadence competition jobs:
# at a daily cadence there is nothing to stack against, and re-entry would be
# harmless anyway given the per-club anchor.
#
# memory_limit is left at the default 256M used by the other jobs — the command
# streams clubs via toIterable() and clears the EntityManager every 500 rows, so
# the working set does not grow with the size of the club table.

CONSOLE=/var/www/html/bin/console
# Run as www-data (the php-fpm runtime user) — crond runs this as root by default,
# and a root-run PHP process would create new var/cache/prod entries as root,
# which php-fpm workers then can't write to.
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== reputation-decay start ==="
if $PHP "$CONSOLE" app:reputation:decay; then
    log "=== reputation-decay done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
