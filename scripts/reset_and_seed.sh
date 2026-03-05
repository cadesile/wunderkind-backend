#!/bin/bash
#
# reset_and_seed.sh
# Resets the Wunderkind database, preserves ROLE_ADMIN users, and re-seeds market data.
#
# Usage:
#   bash scripts/reset_and_seed.sh
#
# To make executable:
#   chmod +x scripts/reset_and_seed.sh

set -e

# ─── Colors ──────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# ─── Config ───────────────────────────────────────────────────────────────────
DB="wunderkind"
BACKUP_FILE="/tmp/wunderkind_admins_backup.json"
RESTORE_SQL="/tmp/wunderkind_admins_restore.sql"
RESET_SQL="/tmp/wunderkind_reset_tables.sql"

# Wrapper: always pass -D so the database is selected in non-interactive mode.
# lando mysql does not auto-select the database when invoked with flags or piped input.
mysql() { lando mysql -D "$DB" "$@"; }

# ─── Safety confirmation ─────────────────────────────────────────────────────
echo ""
echo -e "${YELLOW}⚠️  WARNING${NC}"
echo "   This will DELETE all academies, players, staff, and game data."
echo "   Admin users (ROLE_ADMIN) and their Admin records will be preserved."
echo ""
echo -n "   Continue? (y/N) "
read -r confirm
echo ""

if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    echo "   Aborted."
    exit 0
fi

# ─── Seed configuration ───────────────────────────────────────────────────────
echo -e "${BLUE}⚙️  Seed configuration${NC}"
echo "   Press Enter to accept defaults."
echo ""

prompt_int() {
    local label="$1" default="$2" varname="$3"
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

echo "   — Market entities (agents, scouts, investors, sponsors) —"
prompt_int "Agents"    25  SEED_AGENTS
prompt_int "Scouts"    30  SEED_SCOUTS
prompt_int "Investors" 20  SEED_INVESTORS
prompt_int "Sponsors"  40  SEED_SPONSORS
echo ""
echo "   — Market pool (unassigned players & coaches) —"
prompt_int "Pool players"  100 SEED_PLAYERS
prompt_int "Pool coaches"  20  SEED_COACHES
prompt_int "Pool scouts"   10  SEED_POOL_SCOUTS
echo ""
echo -e "   ${GREEN}Configuration confirmed:${NC}"
echo "     Agents ${SEED_AGENTS} · Scouts ${SEED_SCOUTS} · Investors ${SEED_INVESTORS} · Sponsors ${SEED_SPONSORS}"
echo "     Pool players ${SEED_PLAYERS} · Pool coaches ${SEED_COACHES} · Pool scouts ${SEED_POOL_SCOUTS}"
echo ""

# ─── Dependency checks ───────────────────────────────────────────────────────
if ! command -v lando &>/dev/null; then
    echo -e "${RED}Error: 'lando' is not in PATH.${NC}"
    echo "Install Lando from https://lando.dev and run 'lando start' first."
    exit 1
fi

if ! lando mysql -D "$DB" -se "SELECT 1" 2>/dev/null | grep -q "1"; then
    echo -e "${RED}Error: Cannot connect to MySQL via Lando.${NC}"
    echo "Run 'lando start' to bring up the environment."
    exit 1
fi

# ─── Phase 1: Back up admin users ────────────────────────────────────────────
echo -e "${BLUE}🔄 Phase 1: Backing up admin users...${NC}"

ADMIN_COUNT=$(mysql -Nse \
    "SELECT COUNT(*) FROM \`user\` WHERE JSON_CONTAINS(roles, '\"ROLE_ADMIN\"')" \
    2>/dev/null)

echo "   Found: ${ADMIN_COUNT} admin user(s)"

# Human-readable JSON backup
mysql -Nse "
SELECT COALESCE(
    JSON_PRETTY(JSON_ARRAYAGG(
        JSON_OBJECT(
            'email',      u.email,
            'roles',      CAST(u.roles AS JSON),
            'createdAt',  DATE_FORMAT(u.created_at, '%Y-%m-%dT%T+00:00'),
            'admin', JSON_OBJECT(
                'department',  a.department,
                'accessLevel', a.access_level
            )
        )
    )),
    '[]'
)
FROM \`user\` u
LEFT JOIN \`admin\` a ON a.user_id = u.id
WHERE JSON_CONTAINS(u.roles, '\"ROLE_ADMIN\"')
" 2>/dev/null > "$BACKUP_FILE"

# Validate JSON backup before proceeding
if [[ "$ADMIN_COUNT" -gt 0 ]]; then
    if [[ ! -s "$BACKUP_FILE" ]]; then
        echo -e "${RED}  ERROR: JSON backup is empty. Aborting.${NC}"
        exit 1
    fi
    echo -e "${GREEN}  ✓ JSON backup → ${BACKUP_FILE}${NC}"
fi

# Restorable SQL — user rows
mysql -Nse "
SELECT CONCAT(
    'INSERT INTO \`user\` (id, email, password, roles, created_at) VALUES (0x',
    HEX(id),           ', ',
    QUOTE(email),      ', ',
    QUOTE(password),   ', ',
    QUOTE(roles),      ', ',
    QUOTE(created_at), ');'
)
FROM \`user\`
WHERE JSON_CONTAINS(roles, '\"ROLE_ADMIN\"')
" 2>/dev/null > "$RESTORE_SQL"

# Restorable SQL — admin rows (appended; must run after user rows due to FK)
mysql -Nse "
SELECT CONCAT(
    'INSERT INTO \`admin\` (id, user_id, department, access_level, created_at) VALUES (0x',
    HEX(a.id),       ', 0x',
    HEX(a.user_id),  ', ',
    IF(a.department IS NULL, 'NULL', QUOTE(a.department)), ', ',
    a.access_level,  ', ',
    QUOTE(a.created_at), ');'
)
FROM \`admin\` a
INNER JOIN \`user\` u ON u.id = a.user_id
WHERE JSON_CONTAINS(u.roles, '\"ROLE_ADMIN\"')
" 2>/dev/null >> "$RESTORE_SQL"

if [[ "$ADMIN_COUNT" -gt 0 && ! -s "$RESTORE_SQL" ]]; then
    echo -e "${RED}  ERROR: SQL restore file is empty. Aborting to protect admin users.${NC}"
    exit 1
fi

echo -e "${GREEN}  ✓ SQL restore → ${RESTORE_SQL}${NC}"

# ─── Phase 2: Truncate tables ────────────────────────────────────────────────
echo ""
echo -e "${BLUE}🗑️  Phase 2: Truncating database (preserving admins)...${NC}"

cat > "$RESET_SQL" << 'SQL'
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE inbox_message;
TRUNCATE TABLE facility;
TRUNCATE TABLE leaderboard_entry;
TRUNCATE TABLE sync_record;
TRUNCATE TABLE transfer;
TRUNCATE TABLE guardian;
TRUNCATE TABLE player_siblings;
TRUNCATE TABLE player;
TRUNCATE TABLE staff;
TRUNCATE TABLE investor;
TRUNCATE TABLE sponsor;
TRUNCATE TABLE scout;
TRUNCATE TABLE agent;
TRUNCATE TABLE academy;
TRUNCATE TABLE admin;
DELETE FROM `user` WHERE NOT JSON_CONTAINS(roles, '"ROLE_ADMIN"');

SET FOREIGN_KEY_CHECKS = 1;
SQL

mysql < "$RESET_SQL"
echo -e "${GREEN}  ✓ All game tables cleared${NC}"

# Restore admin users (user rows first, then admin rows due to FK)
if [[ "$ADMIN_COUNT" -gt 0 ]]; then
    echo "   Restoring admin users..."
    mysql < "$RESTORE_SQL"

    RESTORED=$(mysql -Nse \
        "SELECT COUNT(*) FROM \`user\` WHERE JSON_CONTAINS(roles, '\"ROLE_ADMIN\"')" \
        2>/dev/null)

    if [[ "$RESTORED" != "$ADMIN_COUNT" ]]; then
        echo -e "${RED}  ERROR: Restoration mismatch — expected ${ADMIN_COUNT}, got ${RESTORED}.${NC}"
        echo -e "${RED}  Restore SQL preserved at: ${RESTORE_SQL}${NC}"
        exit 1
    fi

    echo -e "${GREEN}  ✓ ${RESTORED} admin user(s) restored${NC}"
fi

# ─── Phase 3: Re-seed market data ────────────────────────────────────────────
# app:generate-market-data is the sole owner of agents (25). app:market:generate
# skips agent generation (--agents=0) to avoid duplicate names from both commands
# drawing the same name pool. Pool players (100), coaches (20), and scouts (10)
# come from app:market:generate only.
echo ""
echo -e "${BLUE}🌱 Phase 3a: Generating market data (agents · scouts · investors · sponsors)...${NC}"
lando php bin/console app:generate-market-data \
    --agents="$SEED_AGENTS" \
    --scouts="$SEED_SCOUTS" \
    --investors="$SEED_INVESTORS" \
    --sponsors="$SEED_SPONSORS"

echo ""
echo -e "${BLUE}🌱 Phase 3b: Generating market pool (players · coaches · scouts, no agents)...${NC}"
lando php bin/console app:market:generate \
    --agents=0 \
    --players="$SEED_PLAYERS" \
    --coaches="$SEED_COACHES" \
    --scouts="$SEED_POOL_SCOUTS"

echo ""
echo -e "${BLUE}🌱 Phase 3c: Seeding game event templates (idempotent)...${NC}"
lando php bin/console app:seed-game-events

# ─── Verification ────────────────────────────────────────────────────────────
echo ""
echo -e "${BLUE}📊 Verifying seeded data...${NC}"

POOL_PLAYERS=$(mysql -Nse "SELECT COUNT(*) FROM \`player\` WHERE academy_id IS NULL" 2>/dev/null)
POOL_STAFF=$(mysql -Nse "SELECT COUNT(*) FROM \`staff\` WHERE academy_id IS NULL" 2>/dev/null)
SCOUTS=$(mysql -Nse "SELECT COUNT(*) FROM \`scout\`" 2>/dev/null)
AGENTS=$(mysql -Nse "SELECT COUNT(*) FROM \`agent\`" 2>/dev/null)
INVESTORS=$(mysql -Nse "SELECT COUNT(*) FROM \`investor\`" 2>/dev/null)
SPONSORS=$(mysql -Nse "SELECT COUNT(*) FROM \`sponsor\`" 2>/dev/null)
EVENT_TEMPLATES=$(mysql -Nse "SELECT COUNT(*) FROM \`game_event_template\`" 2>/dev/null)

echo "   Pool players    : ${POOL_PLAYERS}"
echo "   Pool coaches    : ${POOL_STAFF}"
echo "   Scouts          : ${SCOUTS}"
echo "   Agents          : ${AGENTS}"
echo "   Investors       : ${INVESTORS}"
echo "   Sponsors        : ${SPONSORS}"
echo "   Event templates : ${EVENT_TEMPLATES}"

# ─── Summary ─────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}✓ Reset complete!${NC}"
echo ""
echo "   Preserved  : ${ADMIN_COUNT} admin user(s)"
echo "   Cleared    : academies, players, guardians, staff, scouts, agents, sponsors,"
echo "                investors, transfers, leaderboard entries, sync records,"
echo "                inbox messages, facilities"
echo "   Regenerated: ${AGENTS} agents · ${SCOUTS} scouts · ${POOL_PLAYERS} pool players"
echo "                ${POOL_STAFF} pool coaches · ${INVESTORS} investors · ${SPONSORS} sponsors"
echo "                ${EVENT_TEMPLATES} event templates"
echo ""
echo "   Backups    :"
echo "     JSON : ${BACKUP_FILE}"
echo "     SQL  : ${RESTORE_SQL}"
echo ""
