#!/bin/sh
# Recalculates leaderboard scores/ranks and invalidates the leaderboard cache
# for all categories/periods. Runs frequently since it's the only path that
# populates rank_position and refreshes the heavier aggregate categories
# (empire_index, golden_boot, playmaker) — reads fall back to on-demand
# computation on a cold cache, but this keeps that path rarely hit.

CONSOLE=/var/www/html/bin/console
PHP="php -d memory_limit=256M"

log() { echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] $*"; }

log "=== leaderboards-generate start ==="
if $PHP "$CONSOLE" app:leaderboards:generate; then
    log "=== leaderboards-generate done ==="
else
    status=$?
    log "  FAILED (exit $status)"
    exit "$status"
fi
