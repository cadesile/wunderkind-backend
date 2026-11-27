#!/usr/bin/env bash
# Go-live step 2: back up dev, and restore that backup as wk_golive.
#
#   bash scripts/golive/make-golive.sh
#
# Take this AFTER the extra countries are set up and their world packs are warm. Admin edits
# made on dev after this point are not in wk_golive: freeze them, or re-run this step (and the
# import after it).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
DUMP="$BACKUP_DIR/dev-$(stamp).dump"

say "Backing up dev (read-only) to $DUMP"
docker exec -e PGPASSWORD="$DEV_DB_PASSWORD" "$DEV_PG" \
    pg_dump -U "$DB_USER" -d "$LIVE_DB" --no-owner --no-acl -F c > "$DUMP"
ls -lh "$DUMP"

say "Recreating $GOLIVE_DB from the dev backup"
recreate_scratch_db "$GOLIVE_DB"
docker exec -i -e PGPASSWORD="$DEV_DB_PASSWORD" "$DEV_PG" \
    pg_restore -U "$DB_USER" -d "$GOLIVE_DB" --no-owner --no-acl --exit-on-error < "$DUMP"

echo "$DUMP" > "$BACKUP_DIR/LATEST_DEV_DUMP"
say "Done. Copy $DUMP off the box before going further. Next: import.sh"
