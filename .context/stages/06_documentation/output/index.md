# Documentation Index

Root `CLAUDE.md` and `AGENTS.md` are excluded — they're the generated
`.context/` pointer stubs (see `SKILL.md`), not hand-written documentation.

## Canonical (human-confirmed, questionnaire Q5)

- `README.md` — project overview, core gameplay pillars, tech stack table, hybrid client-authoritative/sync architecture summary
- `docs/api/account-delete.md` — API spec: irreversible account-deletion endpoint
- `docs/api/admin-messages-client-integration.md` — how the game client consumes server-driven `AdminMessage` announcements (companion to `server-driven-messaging.md`)
- `docs/api/community-stats.md` — four public, live-computed leaderboard endpoints ranking clubs by activity
- `docs/api/leaderboard.md` — twelve public, cached leaderboard categories (`GET /api/leaderboard/{category}`)
- `docs/api/npc-club-city-size.md` — spec for `region`/`citySize`/`populationSize`/`isCapital` fields on NPC club objects
- `docs/api/server-driven-messaging.md` — operator-authored announcements composed in admin, polled by client, shown once per club
- `docs/deploy/hetzner.md` — deployment runbook for the single Hetzner box hosting this app
- `docs/event-guide.md` — how `GameEventTemplate` rows are shaped and which fields the client acts on
- `docs/frontend-integration.md` — guide for the separate React Native app on integrating with this API (MMKV storage, typed client layer)
- `docs/frontend-spec-player-physical-personality.md` — spec for server-generated `height`/`weight`/`personality` fields on player objects

## Historical design records (not living docs)

Dated plans/specs under `docs/superpowers/plans/` and `docs/superpowers/specs/` —
one pair per shipped feature (npc-club-generation, initialize-endpoint-redesign,
worldpack-cache-admin, beta-request-queue, player-generation-pipeline,
club-init-pool-prewarm, remove-player-staff-club-fk,
community-stats-landing-page, social-post-templates-and-cron,
club-name-options-canonical-source, backend-appearance,
account-deletion-endpoint, npc-club-city-size-weighting). These record the
design/plan at the time a feature was built; treat as historical context,
not current-state documentation — cross-check against code/entities for
anything time-sensitive.

## Stale / superseded

- `docs/wunderkind-backend-context.md` — output of the retired, pre-ICM
  generator script (see repo root README's "Why this exists"); dated
  2026-06-19, superseded by this `.context/` tree. Not re-indexed as
  canonical.

## Other

- `migrations/archive/README.md` — explains the 29 archived MySQL
  migrations (pre-Postgres-migration, 2026-03-26); says plainly not to run
  them
- `src/Controller/Admin/CLAUDE.md` — hand-written tribal knowledge: EasyAdmin
  custom-route redirect convention (must go through EasyAdmin's entry point)
- `.context/stages/04_interfaces/output/push-notifications.md` (2026-09-20) — API spec for
  FCM device-token registration and the push-payload contract (`ROUND_DRAWN`,
  `NEW_REGISTRANT`, `MATCH_RESULT`, `ADMIN_MESSAGE`). Lives in `.context/` rather than
  `docs/api/` by request — moved from `docs/api/push-notifications.md`, which no longer
  exists.

## New since the last human-confirmed pass (not yet re-run through
the questionnaire Q5 Checkpoint — added here for discoverability, not
claiming canonical status)

- `docs/api/competition-registration-snapshot-v2.md` (2026-09-19) — additive
  snapshot fields for match-engine parity, plus a `MATCH`/`MATCH_NARRATIVE`
  client-integration section (retiring bundled `narrativeContent.json`,
  display scoping, impacts-still-apply note)
