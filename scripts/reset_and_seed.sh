#!/bin/bash
#
# reset_and_seed.sh
# Resets the Wunderkind database, re-seeds market data,
# and restores game_config + starter_config to hardcoded defaults.
#
# The "admin" table is NEVER touched — admin users are always preserved.
#
# Usage:
#   bash scripts/reset_and_seed.sh           # interactive (local / Lando)
#   bash scripts/reset_and_seed.sh --yes     # non-interactive, all defaults (CI / deploy)
#
# Connection mode is auto-detected:
#   Lando available + .lando.yml present  →  lando psql / lando php bin/console
#   Otherwise                             →  psql CLI + php bin/console
#                                            (reads DATABASE_URL from env or .env)

set -e

# ─── Flags ────────────────────────────────────────────────────────────────────
NON_INTERACTIVE=false
for arg in "$@"; do
    [[ "$arg" == "--yes" || "$arg" == "-y" ]] && NON_INTERACTIVE=true
done

# ─── Colors ──────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# ─── Connection mode ──────────────────────────────────────────────────────────
# psql_cmd  — runs a single SQL query, returns plain text value (tuples-only, unaligned)
# psql_file — executes a SQL file; for lando the file must be inside the project dir
#             (lando mounts the project at /app, so host ./foo.sql = container /app/foo.sql)
RESET_SQL_HOST=".reset_tmp.sql"
RESET_SQL_CONTAINER="/app/.reset_tmp.sql"

if command -v lando &>/dev/null && [[ -f ".lando.yml" ]]; then
    CONNECTION_MODE="lando"
    psql_cmd()  { lando psql -tAc "$1"; }
    psql_file() { lando psql -f "$RESET_SQL_CONTAINER"; }
    console_cmd() { lando php bin/console "$@"; }
    echo -e "${BLUE}ℹ  Connection mode: Lando (PostgreSQL)${NC}"
else
    CONNECTION_MODE="native"
    if [[ -z "$DATABASE_URL" && -f ".env" ]]; then
        _db_line=$(grep -m1 '^DATABASE_URL=' .env 2>/dev/null || true)
        [[ -n "$_db_line" ]] && export DATABASE_URL="${_db_line#DATABASE_URL=}"
        DATABASE_URL="${DATABASE_URL#\"}"
        DATABASE_URL="${DATABASE_URL%\"}"
        # Strip Symfony serverVersion param — psql doesn't understand it
        DATABASE_URL="${DATABASE_URL%%\?*}"
    fi
    if [[ -z "$DATABASE_URL" ]]; then
        echo -e "${RED}Error: DATABASE_URL is not set. Export it or ensure .env is present.${NC}"
        exit 1
    fi
    psql_cmd()  { psql "$DATABASE_URL" -tAc "$1"; }
    psql_file() { psql "$DATABASE_URL" -f "$RESET_SQL_HOST"; }
    console_cmd() { php bin/console "$@"; }
    echo -e "${BLUE}ℹ  Connection mode: native (PostgreSQL — ${DATABASE_URL%%@*}@...)${NC}"
fi

# ─── Safety confirmation ─────────────────────────────────────────────────────
echo ""
echo -e "${YELLOW}⚠️  WARNING${NC}"
echo "   This will DELETE all academies, players, staff, and game data."
echo "   The admin table is untouched — admin users are always preserved."
echo "   game_config and starter_config will be reset to defaults."
echo ""

if [[ "$NON_INTERACTIVE" == "false" ]]; then
    echo -n "   Continue? (y/N) "
    read -r confirm
    echo ""
    if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
        echo "   Aborted."
        exit 0
    fi
else
    echo -e "   ${YELLOW}--yes flag set — proceeding with all defaults, no prompts.${NC}"
    echo ""
fi

# ─── Seed configuration ───────────────────────────────────────────────────────
prompt_int() {
    local label="$1" default="$2" varname="$3"
    if [[ "$NON_INTERACTIVE" == "true" ]]; then
        eval "$varname=$default"
        return
    fi
    echo -n "   ${label} [${default}]: "
    read -r input
    if [[ -z "$input" ]]; then
        eval "$varname=$default"
    elif [[ "$input" =~ ^[0-9]+$ ]]; then
        eval "$varname=$input"
    else
        echo -e "   ${RED}Invalid value '${input}' — using default ${default}.${NC}"
        eval "$varname=$default"
    fi
}

if [[ "$NON_INTERACTIVE" == "false" ]]; then
    echo -e "${BLUE}⚙️  Seed configuration${NC}"
    echo "   Press Enter to accept defaults."
    echo ""
    echo "   — Market entities (agents, scouts, investors, sponsors) —"
fi

prompt_int "Agents"    100  SEED_AGENTS
prompt_int "Scouts"    100  SEED_SCOUTS
prompt_int "Investors" 100  SEED_INVESTORS
prompt_int "Sponsors"  100  SEED_SPONSORS

if [[ "$NON_INTERACTIVE" == "false" ]]; then
    echo ""
    echo "   — Market pool (unassigned players & coaches) —"
fi

prompt_int "Pool players"     5000 SEED_PLAYERS
prompt_int "Prospect players" 2000 SEED_PROSPECTS
prompt_int "Pool coaches"      100 SEED_COACHES
prompt_int "Pool scouts"       100 SEED_POOL_SCOUTS

# ─── Review / summary ─────────────────────────────────────────────────────────
if [[ "$NON_INTERACTIVE" == "false" ]]; then
    while true; do
        echo ""
        echo -e "${BLUE}📋 Seed summary — review before proceeding:${NC}"
        echo ""
        echo "     [1] Agents           : ${SEED_AGENTS}"
        echo "     [2] Scouts           : ${SEED_SCOUTS}"
        echo "     [3] Investors        : ${SEED_INVESTORS}"
        echo "     [4] Sponsors         : ${SEED_SPONSORS}"
        echo "     [5] Pool players     : ${SEED_PLAYERS}"
        echo "     [6] Prospect players : ${SEED_PROSPECTS}"
        echo "     [7] Pool coaches     : ${SEED_COACHES}"
        echo "     [8] Pool scouts      : ${SEED_POOL_SCOUTS}"
        echo ""
        echo "   Enter a number to edit that value, or press Enter to proceed."
        echo -n "   Choice: "
        read -r choice

        case "$choice" in
            1) prompt_int "Agents"           "$SEED_AGENTS"      SEED_AGENTS ;;
            2) prompt_int "Scouts"           "$SEED_SCOUTS"      SEED_SCOUTS ;;
            3) prompt_int "Investors"        "$SEED_INVESTORS"   SEED_INVESTORS ;;
            4) prompt_int "Sponsors"         "$SEED_SPONSORS"    SEED_SPONSORS ;;
            5) prompt_int "Pool players"     "$SEED_PLAYERS"     SEED_PLAYERS ;;
            6) prompt_int "Prospect players" "$SEED_PROSPECTS"   SEED_PROSPECTS ;;
            7) prompt_int "Pool coaches"     "$SEED_COACHES"     SEED_COACHES ;;
            8) prompt_int "Pool scouts"      "$SEED_POOL_SCOUTS" SEED_POOL_SCOUTS ;;
            "") break ;;
            *) echo -e "   ${RED}Invalid choice — enter 1-8 or press Enter to proceed.${NC}" ;;
        esac
    done
else
    echo -e "${BLUE}📋 Seed summary (defaults):${NC}"
    echo ""
    echo "     Agents           : ${SEED_AGENTS}"
    echo "     Scouts           : ${SEED_SCOUTS}"
    echo "     Investors        : ${SEED_INVESTORS}"
    echo "     Sponsors         : ${SEED_SPONSORS}"
    echo "     Pool players     : ${SEED_PLAYERS}"
    echo "     Prospect players : ${SEED_PROSPECTS}"
    echo "     Pool coaches     : ${SEED_COACHES}"
    echo "     Pool scouts      : ${SEED_POOL_SCOUTS}"
fi

echo ""
echo -e "   ${GREEN}✓ Configuration locked in. Starting reset...${NC}"
echo ""

# ─── Phase 1: Truncate game tables (admin table is intentionally excluded) ────
echo -e "${BLUE}🗑️  Phase 1: Truncating game tables + resetting config to defaults...${NC}"
echo "   (admin table is skipped — admin users are always preserved)"

# Write SQL to a file inside the project dir (lando mounts project at /app).
# The admin table is intentionally absent from this list.
cat > "$RESET_SQL_HOST" << 'SQL'
-- Truncate all game tables; CASCADE resolves FK order automatically.
-- admin table is intentionally excluded.
TRUNCATE TABLE
    inbox_message,
    facility,
    leaderboard_entry,
    sync_record,
    transfer,
    guardian,
    player_siblings,
    player,
    staff,
    investor,
    sponsor,
    scout,
    agent,
    academy,
    "user"
CASCADE;

-- Reset game_config (RESTART IDENTITY resets the PK sequence)
TRUNCATE TABLE game_config RESTART IDENTITY CASCADE;
INSERT INTO game_config (
    clique_relationship_threshold, clique_squad_cap_percent, clique_min_tenure_weeks,
    base_xp, base_injury_probability,
    regression_upper_threshold, regression_lower_threshold,
    reputation_delta_base, reputation_delta_facility_multiplier,
    injury_minor_weight, injury_moderate_weight, injury_serious_weight
) VALUES (20, 30, 3, 10, 0.05, 14, 7, 0.5, 1.2, 60, 30, 10);

-- Reset starter_config
TRUNCATE TABLE starter_config RESTART IDENTITY CASCADE;
INSERT INTO starter_config (id, starting_balance, starter_player_count, starter_coach_count, starter_scout_count, starter_sponsor_tier)
VALUES (1, 5000000, 5, 1, 1, 'SMALL');
SQL

psql_file
rm -f "$RESET_SQL_HOST"

echo -e "${GREEN}  ✓ All game tables cleared${NC}"
echo -e "${GREEN}  ✓ game_config  — reset to defaults (clique 20/30/3, baseXP 10, injury 0.05, weights 60/30/10)${NC}"
echo -e "${GREEN}  ✓ starter_config — reset to defaults (balance £5m, 5 players, 1 coach, 1 scout, SMALL sponsor)${NC}"

# ─── Phase 2: Re-seed market data ────────────────────────────────────────────
echo ""
echo -e "${BLUE}🌱 Phase 2a: Generating market data (agents · scouts · investors · sponsors)...${NC}"
console_cmd app:generate-market-data \
    --agents="$SEED_AGENTS" \
    --scouts="$SEED_SCOUTS" \
    --investors="$SEED_INVESTORS" \
    --sponsors="$SEED_SPONSORS"

echo ""
echo -e "${BLUE}🌱 Phase 2b: Generating market pool (players · prospects · coaches · scouts)...${NC}"
console_cmd app:market:generate \
    --agents=0 \
    --players="$SEED_PLAYERS" \
    --prospects="$SEED_PROSPECTS" \
    --coaches="$SEED_COACHES" \
    --scouts="$SEED_POOL_SCOUTS"

echo ""
echo -e "${BLUE}🌱 Phase 2c: Seeding game event templates (idempotent)...${NC}"
console_cmd app:seed-game-events

echo ""
echo -e "${BLUE}🌱 Phase 2d: Seeding player archetypes...${NC}"
console_cmd app:seed-archetypes

# ─── Verification ────────────────────────────────────────────────────────────
echo ""
echo -e "${BLUE}📊 Verifying seeded data...${NC}"
echo ""

# All counts in one file-based query — avoids lando TTY issues with subshells.
cat > "$RESET_SQL_HOST" << 'SQL'
SELECT
    'Market players'   AS label, COUNT(*)::text AS value FROM player WHERE academy_id IS NULL AND recruitment_source = 'youth_intake'
UNION ALL SELECT 'Prospect players', COUNT(*)::text FROM player WHERE academy_id IS NULL AND recruitment_source = 'scouting_network'
UNION ALL SELECT 'Pool coaches',     COUNT(*)::text FROM staff   WHERE academy_id IS NULL
UNION ALL SELECT 'Scouts',           COUNT(*)::text FROM scout
UNION ALL SELECT 'Agents',           COUNT(*)::text FROM agent
UNION ALL SELECT 'Investors',        COUNT(*)::text FROM investor
UNION ALL SELECT 'Sponsors',         COUNT(*)::text FROM sponsor
UNION ALL SELECT 'Event templates',  COUNT(*)::text FROM game_event_template
UNION ALL SELECT 'Archetypes',       COUNT(*)::text FROM player_archetype
UNION ALL SELECT 'Admin users',      COUNT(*)::text FROM admin
UNION ALL SELECT 'game_config',      clique_relationship_threshold||'/'||clique_squad_cap_percent||'/'||clique_min_tenure_weeks||' · baseXP='||base_xp||' · injury='||base_injury_probability FROM game_config LIMIT 1
UNION ALL SELECT 'starter_config',   'balance='||starting_balance||' · players='||starter_player_count||' · sponsor='||starter_sponsor_tier FROM starter_config WHERE id = 1;
SQL

psql_file
rm -f "$RESET_SQL_HOST"

# ─── Summary ─────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}✓ Reset complete!${NC}"
echo ""
echo "   Cleared    : academies, players, guardians, staff, scouts, agents, sponsors,"
echo "                investors, transfers, leaderboard entries, sync records,"
echo "                inbox messages, facilities"
echo "   Config     : game_config + starter_config reset to defaults"
echo "   Admin      : untouched"
echo ""
