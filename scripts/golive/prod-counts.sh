#!/usr/bin/env bash
# READ-ONLY row counts on PRODUCTION, to size the go-live import and the club buyout.
#
# Runs every query inside a read-only transaction (default_transaction_read_only=on), so
# even a mistake here cannot write. It prints counts and money totals only: no emails, no
# names, nothing personal leaves the database.
#
#   bash scripts/golive/prod-counts.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PROD_DB_PASSWORD="$(env_value "$PROD_DIR" DB_PASSWORD)"

say "Production row counts (read-only)"
live_psql_ro "$PROD_PG" "$PROD_DB_PASSWORD" -f - < "$SCRIPT_DIR/sql/counts.sql"
