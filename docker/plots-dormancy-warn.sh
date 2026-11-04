#!/bin/sh
# Warns owners whose clubs have gone quiet that their plot is about to be reclaimed
# (app:plots:dormancy-warn). Two stages: 23 days inactive, and 29.
#
# MUST RUN BEFORE plots-dormancy-reclaim.sh, and the crontab schedules it twenty minutes
# earlier for that reason. The reclaim enforces the ordering itself — it refuses to take a plot
# with no DELIVERED one-day warning for the current dormancy cycle — so a missed run here delays
# a reclaim rather than causing an unwarned one. That is the intended failure mode: a plot
# carries real credit, and taking one from a player who was never told is not defensible.
#
# Idempotent. The warning row is claimed with INSERT ... ON CONFLICT DO NOTHING, so a second run
# on the same day sends nothing further, and overlapping runs cannot both warn.
#
# Deliberately NOT guarded by flock, like reputation-decay.sh: at a daily cadence there is
# nothing to stack against, and re-entry is harmless anyway given the atomic dedupe above.
#
# Scheduled at 03:40 UTC — clear of reputation-decay (03:20) and of the spotlight runs on the
# hour, so the three daily jobs do not contend.

CONSOLE=/var/www/html/bin/console
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== plots-dormancy-warn start ==="
if $PHP "$CONSOLE" app:plots:dormancy-warn; then
    log "=== plots-dormancy-warn done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
