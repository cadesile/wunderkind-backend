#!/usr/bin/env bash
# Go-live step 3: empty wk_golive of dev's players, then load production's accounts into it.
#
#   bash scripts/golive/import.sh
#
# Needs stage-prod.sh and make-golive.sh first. Safe to re-run from make-golive.sh onwards:
# both scratch databases are rebuilt from their dumps, so a failed run is thrown away.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

say "Purging dev's players from $GOLIVE_DB"
dev_psql "$GOLIVE_DB" < "$SCRIPT_DIR/sql/purge-dev-users.sql"

say "Picking production's accounts out of $STAGE_DB"
dev_psql "$STAGE_DB" < "$SCRIPT_DIR/sql/export-accounts.sql"

say "Moving them across"
dev_psql "$GOLIVE_DB" -c 'DROP SCHEMA IF EXISTS golive_export CASCADE'
docker exec -e PGPASSWORD="$DEV_DB_PASSWORD" "$DEV_PG" \
    pg_dump -U "$DB_USER" -d "$STAGE_DB" -n golive_export --no-owner --no-acl \
  | dev_psql "$GOLIVE_DB" -o /dev/null

say "Importing"
dev_psql "$GOLIVE_DB" < "$SCRIPT_DIR/sql/import-accounts.sql"

say "Verifying"
dev_psql "$GOLIVE_DB" < "$SCRIPT_DIR/sql/verify-import.sql"

say "Done. Next: sell.sh --dry-run"
