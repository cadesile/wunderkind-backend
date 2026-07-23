# migrations/archive/README.md

> Title: Archived MySQL Migrations · 38 words · parsed 2026-07-21T21:26:02.039Z

## Outline
_No sub-headings._

## Summary
This README documents 29 archived MySQL 8.0 migration files that were replaced by a single fresh PostgreSQL baseline migration during the 2026-03-26 migration to Postgres. An agent should read it only if it's about to touch files in `migrations/archive/` — the key takeaway is that these migrations must never be run, since they use MySQL-specific syntax (ENGINE=InnoDB, BINARY(16) UUIDs, AUTO_INCREMENT) incompatible with the current Postgres schema.
