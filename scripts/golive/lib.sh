#!/usr/bin/env bash
# Shared settings and guards for the go-live migration scripts. Sourced, never run.
#
# Every script here runs ON THE HETZNER BOX, from /mnt/volume-wkf/wunderkind/dev (the dev
# compose project), as the deploy user. See scripts/golive/README.md for the order.
#
# THE ONE RULE THESE GUARDS ENFORCE: nothing in this directory writes to a database called
# `wunderkind`. Both the live dev site and production use that name, inside postgres-dev and
# postgres-prod. The go-live work happens in two scratch databases inside postgres-dev:
#
#   wk_prod_stage   a restored copy of prod, migrated to development's schema
#   wk_golive       a restored copy of dev, which prod's accounts are imported into
#
# Only promote.sh touches postgres-prod, and only after its own typed confirmation.

set -euo pipefail

DEV_DIR="${DEV_DIR:-/mnt/volume-wkf/wunderkind/dev}"
PROD_DIR="${PROD_DIR:-/mnt/volume-wkf/wunderkind/prod}"
DEV_PG="${DEV_PG:-postgres-dev}"
PROD_PG="${PROD_PG:-postgres-prod}"
DB_USER="${DB_USER:-wunderkind}"
LIVE_DB="wunderkind"
STAGE_DB="${STAGE_DB:-wk_prod_stage}"
GOLIVE_DB="${GOLIVE_DB:-wk_golive}"
BACKUP_DIR="${BACKUP_DIR:-/mnt/volume-wkf/wunderkind/golive-backups}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Read one variable out of an environment's .env without printing it.
env_value() { grep -m1 "^$2=" "$1/.env" | cut -d'=' -f2- | sed -e 's/^"//' -e 's/"$//'; }

DEV_DB_PASSWORD="$(env_value "$DEV_DIR" DB_PASSWORD)"

stamp() { date -u +%Y%m%dT%H%M%SZ; }
say()   { printf '\033[0;34m==> %s\033[0m\n' "$*"; }
warn()  { printf '\033[1;33m!!  %s\033[0m\n' "$*"; }
die()   { printf '\033[0;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }

# Refuse to write anywhere but the two scratch databases.
assert_scratch_db() {
    case "$1" in
        "$STAGE_DB"|"$GOLIVE_DB") ;;
        *) die "refusing to write to database '$1': only $STAGE_DB and $GOLIVE_DB are allowed here" ;;
    esac
}

# psql inside postgres-dev against a scratch database. Reads SQL from stdin or -f.
dev_psql() {
    local db="$1"; shift
    assert_scratch_db "$db"
    docker exec -i -e PGPASSWORD="$DEV_DB_PASSWORD" "$DEV_PG" \
        psql -U "$DB_USER" -d "$db" -v ON_ERROR_STOP=1 -X -q "$@"
}

# Read-only psql against a live database (counts, checks). Never pass it a write.
live_psql_ro() {
    local container="$1" pw="$2"; shift 2
    docker exec -i -e PGPASSWORD="$pw" -e PGOPTIONS='-c default_transaction_read_only=on' "$container" \
        psql -U "$DB_USER" -d "$LIVE_DB" -v ON_ERROR_STOP=1 -X -q "$@"
}

# (Re)create an empty scratch database inside postgres-dev.
recreate_scratch_db() {
    local db="$1"
    assert_scratch_db "$db"
    docker exec -i -e PGPASSWORD="$DEV_DB_PASSWORD" "$DEV_PG" psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -X -q <<SQL
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db' AND pid <> pg_backend_pid();
DROP DATABASE IF EXISTS $db;
CREATE DATABASE $db OWNER $DB_USER;
SQL
}

# Run a Symfony console command in a one-off container from the dev image, pointed at a
# scratch database. The live dev container and its crons are not touched: they keep using
# `wunderkind`. --no-deps so compose does not restart postgres-dev.
console_on() {
    local db="$1"; shift
    assert_scratch_db "$db"
    (cd "$DEV_DIR" && docker compose -f docker-compose.dev.yml run --rm --no-deps -T \
        -e DATABASE_URL="postgresql://$DB_USER:$DEV_DB_PASSWORD@$DEV_PG:5432/$db?serverVersion=16&charset=utf8" \
        app su-exec www-data php bin/console "$@")
}

# Ask for a typed word before anything that cannot be undone.
confirm_word() {
    local word="$1" prompt="$2" answer
    [[ "${GOLIVE_YES:-}" == "$word" ]] && return 0
    printf '%s\nType %s to continue: ' "$prompt" "$word"
    read -r answer
    [[ "$answer" == "$word" ]] || die "not confirmed"
}
