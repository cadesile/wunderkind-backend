#!/usr/bin/env bash
set -euo pipefail

# Pulls a full copy of the PRODUCTION database (wunderkind-prod on the Hetzner box) and
# restores it into the local Lando dev database, replacing whatever is there now.
#
# ⚠️  RAW DATA — NOT SCRUBBED. The restored copy includes real user PII verbatim: emails,
#    owner identity (name/nationality/gender/dob), GDPR DeletionRequest rows (email + IP),
#    and FCM device tokens. This was a deliberate choice (local dev against realistic data
#    volume/shape), not an oversight — re-read this banner before deciding to re-use this
#    script for anything beyond solo local development on a machine you control.
#
#    Two fields come across as useless-but-harmless rather than live secrets: OAuth tokens on
#    social_account_connection are encrypted with the PROD-only SOCIAL_TOKEN_ENCRYPTION_KEY,
#    which your local env does not have, so they decrypt to nothing locally; nothing here
#    grants access to a real social account. Push notifications: local Firebase credentials
#    (if configured at all) are dev-only, so a real device token found here cannot receive a
#    push triggered from your local environment.
#
#    Do not commit the dump file (it lands under var/, already gitignored) and do not copy it
#    anywhere outside your own machine.
#
# Usage:
#   bash scripts/pull-prod-db.sh              # interactive confirmation
#   bash scripts/pull-prod-db.sh --yes        # skip the confirmation prompt
#   bash scripts/pull-prod-db.sh --keep-dump  # don't delete the local .sql file afterward
#                                              # (lets a re-run skip re-pulling from prod —
#                                              # see --from-dump)
#   bash scripts/pull-prod-db.sh --from-dump var/prod-dumps/<file>.sql
#                                              # restore an already-downloaded dump instead of
#                                              # pulling a fresh one (no SSH/prod access needed)
#
# Requires: SSH access to the production host (same key used for manual deploy operations —
# see docs/deploy/hetzner.md), and Lando running locally (`lando start`).

PROD_SSH_HOST="root@204.168.207.171"
PROD_CONTAINER="postgres-prod"
PROD_ENV_FILE="/mnt/volume-wkf/wunderkind/prod/.env"
PROD_DB_USER="wunderkind"
PROD_DB_NAME="wunderkind"

LOCAL_DUMP_DIR="var/prod-dumps"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

NON_INTERACTIVE=false
KEEP_DUMP=false
FROM_DUMP=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes|-y)    NON_INTERACTIVE=true; shift ;;
        --keep-dump) KEEP_DUMP=true; shift ;;
        --from-dump) FROM_DUMP="$2"; shift 2 ;;
        *) echo -e "${RED}Unknown argument: $1${NC}"; exit 1 ;;
    esac
done

if ! command -v lando &>/dev/null || [[ ! -f ".lando.yml" ]]; then
    echo -e "${RED}Error: run this from the repo root with Lando installed and running.${NC}"
    exit 1
fi

echo -e "${YELLOW}⚠️  WARNING — this will REPLACE your local Lando database with a RAW,${NC}"
echo -e "${YELLOW}   unscrubbed copy of PRODUCTION data, including real user emails and other PII.${NC}"
echo "   See the banner at the top of this script before re-using it beyond solo local dev."
echo ""

if [[ "$NON_INTERACTIVE" == "false" ]]; then
    echo -n "   Continue? (y/N) "
    read -r confirm
    echo ""
    if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
        echo "   Aborted."
        exit 0
    fi
fi

mkdir -p "$LOCAL_DUMP_DIR"

if [[ -n "$FROM_DUMP" ]]; then
    if [[ ! -f "$FROM_DUMP" ]]; then
        echo -e "${RED}Error: dump file not found: $FROM_DUMP${NC}"
        exit 1
    fi
    LOCAL_DUMP_PATH="$FROM_DUMP"
    echo -e "${BLUE}ℹ  Restoring from existing dump: $LOCAL_DUMP_PATH (no prod connection needed)${NC}"
else
    TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
    REMOTE_DUMP_PATH="/tmp/wunderkind_prod_${TIMESTAMP}.sql"
    LOCAL_DUMP_PATH="${LOCAL_DUMP_DIR}/wunderkind_prod_${TIMESTAMP}.sql"

    echo -e "${BLUE}📥 Dumping production database on the server (read-only against prod)...${NC}"
    # Everything that touches DB_PASSWORD happens in this one remote shell — the value is
    # read from the server's own .env and consumed by docker exec without ever being printed,
    # returned over the SSH channel, or touching the local machine.
    ssh -o ConnectTimeout=15 "$PROD_SSH_HOST" \
        "REMOTE_DUMP_PATH='$REMOTE_DUMP_PATH' PROD_ENV_FILE='$PROD_ENV_FILE' PROD_CONTAINER='$PROD_CONTAINER' PROD_DB_USER='$PROD_DB_USER' PROD_DB_NAME='$PROD_DB_NAME' bash -s" <<'REMOTE_SCRIPT'
        set -euo pipefail
        DB_PASSWORD="$(grep -m1 '^DB_PASSWORD=' "$PROD_ENV_FILE" | cut -d'=' -f2-)"
        DB_PASSWORD="${DB_PASSWORD%\"}"
        DB_PASSWORD="${DB_PASSWORD#\"}"
        docker exec -e PGPASSWORD="$DB_PASSWORD" "$PROD_CONTAINER" \
            pg_dump -U "$PROD_DB_USER" -d "$PROD_DB_NAME" --no-owner --no-acl -F p \
            > "$REMOTE_DUMP_PATH"
REMOTE_SCRIPT

    echo -e "${BLUE}📦 Transferring dump to ${LOCAL_DUMP_PATH}...${NC}"
    scp -q -o ConnectTimeout=15 "${PROD_SSH_HOST}:${REMOTE_DUMP_PATH}" "$LOCAL_DUMP_PATH"

    echo -e "${BLUE}🧹 Removing the dump from the production server...${NC}"
    ssh -o ConnectTimeout=15 "$PROD_SSH_HOST" "rm -f '$REMOTE_DUMP_PATH'"
fi

echo -e "${BLUE}🔧 Normalizing the dump for this psql version...${NC}"
# Newer pg_dump point releases (e.g. 16.15, confirmed on prod) wrap the dump in
# \restrict/\unrestrict meta-commands (a psql-side safety guard, unrelated to the actual
# data) that an older psql (e.g. Lando's 16.6) doesn't recognize — "invalid command
# \restrict". Safe to strip: we generated this dump ourselves moments ago, there's nothing
# to guard against here.
sed -i.bak -E '/^\\(un)?restrict /d' "$LOCAL_DUMP_PATH" && rm -f "${LOCAL_DUMP_PATH}.bak"

echo -e "${BLUE}🗑️  Dropping and recreating the local Lando database...${NC}"
# Can't DROP the database you're connected to — connect to the `postgres` maintenance DB
# instead, AND as the `postgres` role specifically: unlike prod's POSTGRES_USER convention,
# Lando's own postgres:16 service does not make `wunderkind` a superuser/owner (confirmed —
# DROP DATABASE as `wunderkind` fails with "must be owner of database wunderkind"), but the
# built-in `postgres` role is always a superuser and connects here with no password needed
# (trusted local socket inside the container).
lando ssh -s database -c "
    psql -U postgres -d postgres -v ON_ERROR_STOP=1 -c \"
        SELECT pg_terminate_backend(pid) FROM pg_stat_activity
        WHERE datname = 'wunderkind' AND pid <> pg_backend_pid();
    \" &&
    psql -U postgres -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE IF EXISTS wunderkind;' &&
    psql -U postgres -d postgres -v ON_ERROR_STOP=1 -c 'CREATE DATABASE wunderkind OWNER wunderkind;'
"

echo -e "${BLUE}📥 Restoring into the local database...${NC}"
# Lando's symfony recipe mounts the project at /app inside the database service too (same
# assumption reset_and_seed.sh's psql_file() already relies on).
lando ssh -s database -c "psql -U wunderkind -d wunderkind -v ON_ERROR_STOP=1 -f /app/${LOCAL_DUMP_PATH}"

echo -e "${BLUE}🔧 Applying any local migrations ahead of production's schema...${NC}"
lando php bin/console doctrine:migrations:migrate --no-interaction

if [[ "$KEEP_DUMP" == "false" ]]; then
    echo -e "${BLUE}🧹 Removing the local dump file (pass --keep-dump to retain it)...${NC}"
    rm -f "$LOCAL_DUMP_PATH"
else
    echo -e "${BLUE}ℹ  Dump kept at ${LOCAL_DUMP_PATH} — re-run with --from-dump ${LOCAL_DUMP_PATH} to restore it again without pulling from prod.${NC}"
fi

echo ""
echo -e "${GREEN}✓ Import complete. Row counts:${NC}"
lando psql -c "
    SELECT 'user' AS table, COUNT(*) FROM \"user\"
    UNION ALL SELECT 'club', COUNT(*) FROM club
    UNION ALL SELECT 'player', COUNT(*) FROM player
    UNION ALL SELECT 'sync_record', COUNT(*) FROM sync_record
    UNION ALL SELECT 'leaderboard_entry', COUNT(*) FROM leaderboard_entry;
"

echo ""
echo -e "${YELLOW}Reminder: this database now holds real production PII. Don't commit the dump,${NC}"
echo -e "${YELLOW}don't screen-share it, and treat it the way you'd treat production access generally.${NC}"
