#!/usr/bin/env bash
# Go-live step 1: copy production into wk_prod_stage and bring it to development's schema.
#
#   bash scripts/golive/stage-prod.sh
#
# Production is only READ: pg_dump runs inside postgres-prod and writes to a file on the box.
# The dump is restored into wk_prod_stage inside postgres-dev, and development's migrations
# are run against it from a one-off container (the same path scripts/pull-prod-db.sh uses
# locally), so prod's rows take the new shape before anything is copied out of them.
#
# The dump is kept in $BACKUP_DIR: it is the archive of everything not imported (sync
# records, inbox, history). It holds real personal data; it never leaves the box.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PROD_DB_PASSWORD="$(env_value "$PROD_DIR" DB_PASSWORD)"
mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
DUMP="$BACKUP_DIR/prod-$(stamp).dump"

say "Dumping production (read-only) to $DUMP"
docker exec -e PGPASSWORD="$PROD_DB_PASSWORD" "$PROD_PG" \
    pg_dump -U "$DB_USER" -d "$LIVE_DB" --no-owner --no-acl -F c > "$DUMP"
ls -lh "$DUMP"

say "Recreating $STAGE_DB in $DEV_PG"
recreate_scratch_db "$STAGE_DB"

say "Restoring the prod dump into $STAGE_DB"
docker exec -i -e PGPASSWORD="$DEV_DB_PASSWORD" "$DEV_PG" \
    pg_restore -U "$DB_USER" -d "$STAGE_DB" --no-owner --no-acl --exit-on-error < "$DUMP"

say "Migrating $STAGE_DB to development's schema"
console_on "$STAGE_DB" doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "$DUMP" > "$BACKUP_DIR/LATEST_PROD_DUMP"
say "Done. $STAGE_DB holds prod at development's schema. Next: make-golive.sh"
