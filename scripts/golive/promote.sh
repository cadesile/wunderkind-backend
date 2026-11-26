#!/usr/bin/env bash
# Go-live step 5 (CUTOVER): replace production's database with wk_golive.
#
#   bash scripts/golive/promote.sh
#
# The only script here that writes to postgres-prod. Run it during the freeze, after the
# final stage-prod.sh -> make-golive.sh -> import.sh -> sell.sh pass, and BEFORE pushing
# master (the prod deploy runs migrations, which must find wk_golive, not the old schema).
#
# It refuses to run while the prod app container is up, takes its own backup of prod
# immediately before replacing it, and asks for a typed confirmation.
#
# Rollback: restore the backup this prints, then redeploy the pre-golive master tag:
#   docker exec -i -e PGPASSWORD=... postgres-prod pg_restore -U wunderkind -d wunderkind \
#       --clean --if-exists --no-owner --no-acl < <that backup>
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PROD_DB_PASSWORD="$(env_value "$PROD_DIR" DB_PASSWORD)"
PROD_APP="${PROD_APP:-wunderkind-prod}"

if [[ "$(docker inspect -f '{{.State.Running}}' "$PROD_APP" 2>/dev/null || echo false)" == "true" ]]; then
    die "$PROD_APP is running. Freeze prod first: (cd $PROD_DIR && docker compose -f docker-compose.prod.yml stop app)"
fi

say "Checking wk_golive passed its buyout checks"
dev_psql "$GOLIVE_DB" < "$SCRIPT_DIR/sql/verify-sale.sql"

# Master-era builds send no X-Client-Api-Version and count as 1; their clubs are gone. From
# the moment prod answers on the new database they get 426 update_required instead of
# syncing into nothing. New builds send 2. See ClientVersionGateSubscriber.
GOLIVE_MIN_CLIENT_API_VERSION="${GOLIVE_MIN_CLIENT_API_VERSION:-2}"
say "Setting the client version gate to $GOLIVE_MIN_CLIENT_API_VERSION in $GOLIVE_DB"
dev_psql "$GOLIVE_DB" -c "UPDATE game_config SET min_client_api_version = $GOLIVE_MIN_CLIENT_API_VERSION"

mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
PROD_BACKUP="$BACKUP_DIR/prod-before-promote-$(stamp).dump"
GOLIVE_DUMP="$BACKUP_DIR/golive-$(stamp).dump"

say "Backing up production to $PROD_BACKUP"
docker exec -e PGPASSWORD="$PROD_DB_PASSWORD" "$PROD_PG" \
    pg_dump -U "$DB_USER" -d "$LIVE_DB" --no-owner --no-acl -F c > "$PROD_BACKUP"
ls -lh "$PROD_BACKUP"

say "Dumping wk_golive (without the import's working schema) to $GOLIVE_DUMP"
docker exec -e PGPASSWORD="$DEV_DB_PASSWORD" "$DEV_PG" \
    pg_dump -U "$DB_USER" -d "$GOLIVE_DB" --exclude-schema=golive_export --no-owner --no-acl -F c > "$GOLIVE_DUMP"
ls -lh "$GOLIVE_DUMP"

confirm_word PROMOTE "This DROPS production's database and replaces it with wk_golive. Backup: $PROD_BACKUP"

say "Replacing production's database"
docker exec -i -e PGPASSWORD="$PROD_DB_PASSWORD" "$PROD_PG" psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -X -q <<SQL
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$LIVE_DB' AND pid <> pg_backend_pid();
DROP DATABASE $LIVE_DB;
CREATE DATABASE $LIVE_DB OWNER $DB_USER;
SQL
docker exec -i -e PGPASSWORD="$PROD_DB_PASSWORD" "$PROD_PG" \
    pg_restore -U "$DB_USER" -d "$LIVE_DB" --no-owner --no-acl --exit-on-error < "$GOLIVE_DUMP"

say "Row counts now on production"
live_psql_ro "$PROD_PG" "$PROD_DB_PASSWORD" -f - < "$SCRIPT_DIR/sql/counts.sql"

say "Done. Next: merge and push master (the deploy migrates, seeds and starts the app)."
say "Prod backup kept at $PROD_BACKUP: copy it off the box."
