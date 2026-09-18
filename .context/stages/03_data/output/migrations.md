# Migration History

Entities under `src/Entity/` are the authoritative current schema —
migrations are the change log, not the source of truth (see
`schema.md`/`entities.md`).

## Archive

`migrations/archive/` holds 29 old MySQL-era migrations plus a
`README.md` stating plainly: *"Archived MySQL migrations... Archived
during migration to PostgreSQL (2026-03-26). Do not run these... A
single fresh baseline migration replaces them for Postgres."* Dead
history — not part of the live migration chain.

## Live migration chain: `migrations/` (133 files)

Most recent migrations (chronological):

1. `Version20260913203511`, `Version20260914090400`,
   `Version20260914094959`, `Version20260914153431` — older, not
   inspected in detail this pass.
2. `Version20260915213526` — creates `live_telemetry_snapshot`
   (`id`, `fixtures_simulated`, `capital_deployed_pence`,
   `generated_at`).
3. `Version20260915215939` — adds `recent_events JSON` to
   `live_telemetry_snapshot`.
4. `Version20260915221026` — adds `goals_scored INT` to
   `live_telemetry_snapshot`.
5. `Version20260915230707` — renames `goals_scored` →
   `results_wins`, adds `results_draws`, `results_losses`.
6. `Version20260915233540` — adds `active_clubs`, `weeks_played` to
   `live_telemetry_snapshot`.
7. `Version20260916112510` — large migration creating the entire
   Competition module schema (`active_competition`,
   `competition_entrant`, `competition_fixture`, `competition_result`,
   and related tables/indexes per `entities.md`'s Competition section),
   including the partial unique index enforcing one open
   `active_competition` per template.
8. `Version20260918205514` (most recent) — adds
   `is_spoof BOOLEAN DEFAULT false NOT NULL` to `club`.

## Takeaway

Active development in mid-to-late September 2026 focused on: (a)
building out `LiveTelemetrySnapshot` incrementally field-by-field, (b)
standing up the full Competition subsystem in one large migration, and
(c) a small `club.is_spoof` flag addition — consistent with
`02_architecture/output/git-activity.md`'s hotspot findings (Competition
feature + admin panel polish).
