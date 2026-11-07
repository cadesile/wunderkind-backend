#!/bin/sh
# Drains the `suggestion` (Doctrine) Messenger transport — SuggestTownsBatchMessage, each of
# which makes ONE billed call to an external AI provider asking for ~50 town names for a
# country, then imports the ones we do not already hold.
#
# Its own transport and its own consumer, deliberately, and NOT shared with `enrichment` even
# though both spend money with the same provider. enrichment-consume.sh drains at --limit=10
# per minute as a budget cap sized for an unattended sweep; an operator who has just clicked
# "Pull towns" and is watching a live progress bar must not queue behind that. See the
# transport's comment in config/packages/messenger.yaml.
#
# --time-limit IS LONG ON PURPOSE, and this is the one number here that is not copied from
# enrichment-consume.sh. At ~30s a batch, a 50s limit would do two batches, overrun to ~60s,
# and then flock would make crond skip the following tick — so the consumer would sit idle for
# most of a minute between pairs of batches, which to someone watching a 2.5s-polling progress
# bar is the bar freezing. That is the experience this whole feature exists to remove. 280s
# drains a full default run (six batches) inside one process, still picks up a deploy's code
# within five minutes, and still bounds a pathological run of provider timeouts.
#
# --limit IS A COST CONTROL, as it is for enrichment: every message spends money. Twelve is two
# full 300-town runs, so an operator who queues a second country immediately is still served by
# the same process, while a misconfiguration stays bounded by the cron cadence. Emphatically not
# worldpack-consume.sh's 200 — those messages are free.
#
# flock guards against crond stacking a second consumer on top of one still waiting on the
# provider. Here it does more than protect the budget: it is what makes the batches of a run
# drain ONE AT A TIME, which the batching depends on for yield. Each batch excludes the names
# its siblings have already offered (TownSuggestionBatchRepository), so overlapping consumers
# would ask the same question twice and pay for the same answer twice. Worse, two processes
# importing the same town compute the same slug and one loses the whole batch to the
# uq_location_country_slug violation. Single-consumer drain is therefore load-bearing, the same
# property WarmWorldPackClubMessageHandler relies on.
#
# NOTE the two places that guarantee does not reach: the lock is per-container, so a second
# replica would break ordering silently, and a hand-run `messenger:consume suggestion` (Lando,
# debugging) takes no lock at all.
#
# memory_limit matches enrichment-consume.sh: the work is one HTTP request and a few hundred
# row inserts, not entity generation.

CONSOLE=/var/www/html/bin/console
LOCKFILE=/tmp/suggestion-consume.lock
PHP="su-exec www-data php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

exec 9>"$LOCKFILE"
if ! flock -n 9; then
    log "=== suggestion-consume: previous run still in progress, skipping ==="
    exit 0
fi

log "=== suggestion-consume start ==="
if $PHP "$CONSOLE" messenger:consume suggestion --time-limit=280 --limit=12 --no-interaction; then
    log "=== suggestion-consume done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
