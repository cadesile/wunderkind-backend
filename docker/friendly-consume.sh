#!/bin/sh
# Drains the `friendly` (Doctrine) Messenger transport — one message per due friendly match,
# queued by friendly-resolve-matches.sh and handled by ResolveFriendlyMatchMessageHandler.
#
# Deliberately separate from competition-consume.sh, though the work is the same shape: both
# run the match engine and narrative generation per message. How many friendlies come due in
# the same minute is decided by players rather than by a schedule, so a busy evening is
# exactly the burst that must not get in front of tournament rounds — and a round is the more
# time-critical of the two, scheduled against wall-clock time with the next round's draw
# waiting behind it. A friendly's result is sealed until the player reveals it, so a late one
# reads as a result not yet in rather than as a visibly missed kickoff. See the `friendly`
# transport's comment in config/packages/messenger.yaml.
#
# Runs every minute (see the Dockerfile crontab), matching the dispatch cadence of the tick
# that feeds it, so a friendly queued on one tick is normally drained on the next.
#
# Bounded by --time-limit and --memory-limit rather than a message count, for the reason
# competition-consume.sh spells out: a count documents a bound it cannot enforce once
# per-message cost varies. A friendly is one 90-minute match — roughly the cost of one
# competition fixture, so a fraction of a 32-fixture round — which is why the time limit
# here can be the same 50s and still clear a far longer backlog per run.
#
# --time-limit=50 keeps a run inside the one-minute cron period (the worker finishes its
# current message first; flock covers the overrun). --memory-limit=256M bounds what actually
# varies: a resolve loads both sides' squads and generates a result, both lineups and a
# narrative. memory_limit sits above it at 512M, matching the other entity-heavy consumers,
# so Messenger stops cleanly rather than PHP dying. Leftovers drain on the next run.
#
# flock guards against crond stacking a second consumer on one still mid-match. Belt-and-
# braces rather than the real safety net: the resolve claim is itself a lease
# (FriendlyChallenge::CLAIM_LEASE_SECONDS), so two consumers could not double-resolve a
# challenge even without the lock — and the stake moves as a ledger_delta on an inbox note
# committed in the same flush as the COMPLETE status, so a retry cannot pay twice either.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/friendly-consume.lock
PHP="su-exec www-data php -d memory_limit=512M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== friendly-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== friendly-consume start ==="
if $PHP "$CONSOLE" messenger:consume friendly --time-limit=50 --memory-limit=256M --no-interaction; then
    log "=== friendly-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
