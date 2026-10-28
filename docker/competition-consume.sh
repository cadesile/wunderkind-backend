#!/bin/sh
# Drains the `competition` (Doctrine) Messenger transport — per-round draw and resolve
# messages queued by competition-draw-rounds.sh and competition-resolve-rounds.sh
# (DrawCompetitionRoundMessageHandler / ResolveCompetitionRoundMessageHandler).
#
# Deliberately separate from pool-consume.sh, worldpack-consume.sh and messenger-consume.sh.
# Round processing is latency-sensitive in a way none of those are: a tournament's next round
# is scheduled against wall-clock time, so a backlog here shows up to players as a late draw
# rather than as a slow background job. Sharing a queue with an overnight pool top-up or an
# admin-triggered country regenerate would put exactly that in front of it.
#
# Runs every minute (see the Dockerfile crontab), matching the dispatch cadence of the two
# cron commands that feed it, so a round queued on one tick is normally drained on the next.
#
# The bounds here are --time-limit and --memory-limit, not a message count, and that is from
# measurement. app:competition:benchmark at capacity 64 puts the slowest resolve at ~7.5s for
# a 32-fixture round, so the --limit=10 this used to carry could never bind: ten worst-case
# messages are ~75s, well past the 50s time limit, which stops the worker after whichever
# message crosses it. The count was documenting a bound it did not enforce.
#
# --time-limit=50 keeps a run inside the one-minute cron period (the worker finishes its
# current message first, so a 7.5s message can push a run to ~57s; flock covers the overrun).
# --memory-limit bounds the thing that actually varies: the same benchmark measured ~16MB peak
# for a 32-fixture resolve, so 256M exits gracefully on a genuine runaway while leaving an
# order of magnitude of normal headroom. Messages left over are drained by the next run.
#
# memory_limit is 512M to match the other entity-heavy consumers, and sits above the
# --memory-limit above so Messenger stops cleanly rather than PHP dying: a resolve loads both
# entrants' squads and generates results, lineups, a narrative timeline and a computation
# audit per fixture.
#
# flock guards against crond stacking a second consumer on top of one still mid-round — same
# pattern as pool-consume.sh and worldpack-consume.sh. Note this is belt-and-braces rather
# than the real safety net: the round claim is itself a lease
# (CompetitionRound::CLAIM_LEASE_SECONDS), so two consumers could not double-process a round
# even without the lock.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/competition-consume.lock
PHP="su-exec www-data php -d memory_limit=512M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== competition-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== competition-consume start ==="
if $PHP "$CONSOLE" messenger:consume competition --time-limit=50 --memory-limit=256M --no-interaction; then
    log "=== competition-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
