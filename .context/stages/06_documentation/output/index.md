# Documentation Index

Root `CLAUDE.md` and `AGENTS.md` are excluded — they're the generated
`.context/` pointer stubs (see `SKILL.md`), not hand-written documentation.

## Canonical (human-confirmed, questionnaire Q5)

- `README.md` — project overview, core gameplay pillars, tech stack table, hybrid client-authoritative/sync architecture summary
- `docs/api/account-delete.md` — API spec: irreversible account-deletion endpoint
- `docs/api/admin-messages-client-integration.md` — how the game client consumes server-driven `AdminMessage` announcements (companion to `server-driven-messaging.md`)
- `docs/api/community-stats.md` — four public, live-computed leaderboard endpoints ranking clubs by activity
- `docs/api/live-telemetry.md` — contract for the public `GET /api/telemetry` (24h counters + incident feed for the React Native app); documents the three stored keys the endpoint strips, that `amountExact` is a pre-formatted GBP string with no raw-pence equivalent, and that `weeksPlayed`/`tableTurnover` are approximate/wider-windowed
- `docs/api/countries.md` — spec for `GET /api/countries` (nationality/country/code mapping) and the new `countryCode` field on generated player/staff/scout objects (not yet human-confirmed via the Q5 questionnaire — added alongside the feature that introduced it)
- `docs/api/leaderboard.md` — twelve public, cached leaderboard categories (`GET /api/leaderboard/{category}`), plus period semantics: a period board is a **growth** board, not a standing, and a closed period is served from an immutable archive
- `docs/api/leaderboard-awards.md` — operator-configured prizes for topping a period board. **No new endpoint**: the prize arrives through the unchanged inbox, keyed off `offerData.type = 'LEADERBOARD_AWARD'`
- `docs/api/npc-club-city-size.md` — spec for `region`/`citySize`/`populationSize`/`isCapital` fields on NPC club objects
- `docs/api/npc-club-staff-scouts.md` — spec for NPC club `staff[]` gaining `director_of_football`/`facility_manager` roles and a new `scouts[]` array, sized per tier via `StarterConfig::$npcSquadConfig` (not yet human-confirmed via the Q5 questionnaire — added alongside the feature that introduced it)
- `docs/api/players-foreign.md` — spec for `GET /api/players/foreign` (random pool draw excluding one nationality), the new `?ignore_country=` param on `GET /api/scout/search`, and the `PlayerBrowseSerializer` shape they share (not yet human-confirmed via the Q5 questionnaire — added alongside the feature that introduced it)
- `docs/api/role-club-403.md` — answers the app's 2026-11-01 backend note: why an authenticated account could be 403'd from every `ROLE_CLUB` endpoint (two legacy `user.roles` shapes, no `role_hierarchy`) and why `GET /api/club/check` is now authentication-only; carries the SQL for diagnosing how widespread the stored shape was, which is the one part not answerable from this repo (not yet human-confirmed via the Q5 questionnaire — added alongside the fix that introduced it)
- `docs/api/server-driven-messaging.md` — operator-authored announcements composed in admin, polled by client, shown once per club
- `docs/deploy/hetzner.md` — deployment runbook for the single Hetzner box hosting this app
- `docs/world-generation/adding-a-country.md` — repeatable runbook for adding a new playable country (enum case, `CountryContentRegistry` content, `app:country:bootstrap`/`app:country:check`) (not yet human-confirmed via the Q5 questionnaire — added alongside the feature that introduced it)
- `docs/event-guide.md` — how `GameEventTemplate` rows are shaped and which fields the client acts on
- `docs/frontend-integration.md` — guide for the separate React Native app on integrating with this API (MMKV storage, typed client layer)
- `docs/frontend-spec-player-physical-personality.md` — spec for server-generated `height`/`weight`/`personality` fields on player objects

## Historical design records (not living docs)

`docs/superpowers/` held one plan/spec pair per shipped feature. It was pruned
on 2026-11-02 (see **Pruned** below); three files survive, each because
something still points at them by name:

- `docs/superpowers/plans/2026-10-28-tournament-concurrency-and-queues.md` — §3.4
  is cited from `CompetitionDrawService`, `CompetitionResultsService` and
  `CompetitionBenchmarkCommand` as the reason round claims and queues are shaped
  the way they are. Read it before changing either service's locking.
- `docs/superpowers/plans/2026-10-22-tournament-and-friendlies-api.md` — the
  7-milestone Tournament/Competition + Friendlies overhaul; cited from
  `shared/last-sync.md`
- `docs/superpowers/specs/2026-07-06-club-name-options-canonical-source-design.md`
  — cited from `docs/world-generation/adding-a-country.md`

These record the design at the time the feature was built; treat as historical
context, not current-state documentation — cross-check against code/entities
for anything time-sensitive.

## Pruned (2026-11-02)

About 1MB of `docs/` was deleted as spent. Nothing here is recoverable from the
tree, only from git history — listed so a dangling reference (in this repo, in
`wunderkind-app`, or in an old commit message) can be recognised rather than
hunted for:

- **23 of the 26 `docs/superpowers/` plans/specs** — features all shipped
  Apr–Aug 2026, zero inbound references.
- **`docs/wunderkind-backend-context.md`** — output of the retired, pre-ICM
  generator script, superseded by this `.context/` tree. Also `context.zip` at
  the repo root, a stale archive of an older `.context` layout.
- **`docs/wunderkind-narrative-2026-04-21.json`** — a one-off download from
  admin's `narrativeExport()`, not a seed or a doc. Narrative rows live in the
  database; `scripts/reset_and_seed.sh` preserves them.
- **Closed one-off episodes** — `2026-09-27-competition-master-merge-firebase-prod.md`
  (both blockers resolved: `PROD_FIREBASE_SERVICE_ACCOUNT_JSON` is wired in
  `deploy-prod.yml`, competition is on `master`); the three
  `2026-10-24-sentry-sweep-*.md` docs (conclusions preserved in
  `shared/last-sync.md`'s 2026-10-24 pass and in `04_interfaces/output/routes.md`'s
  retry table); `2026-10-28-periodic-leaderboard-awards-plan.md`, which still
  read "planned, not started" after the feature shipped; the two 2026-10-31
  avatar16-v4 / stadium-v3 integration reports, both self-declared integrated;
  and `docs/api/tournament-sealed-results-backend-work.md`, a work log misfiled
  as an API contract (all four items actioned 2026-10-31).

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
- `docs/api/translations.md` (2026-10-03) — `GET /api/languages` +
  `GET /api/translations/{code}[/version]` (generic UI-copy catalogue, same
  shape as the bundled locale files), plus the `?lang=` localize-in-place
  param on `/api/events/templates`, `/api/excursions`, `/api/game-config`
  for narrative content — see "Languages & Translations" in CLAUDE.md
- `docs/api/debug-logging.md` (2026-10-24) — where a client reads
  `debugLoggingEnabled` now that it can vary per account. Says to read it from the
  authenticated `GET /api/club/status` rather than the public
  `GET /api/game-config`, because an anonymous cold-boot fetch of the latter can
  only ever see the global switch; and that the per-account flag is additive to the
  global one, never an override downwards — see `User` in `03_data/output/entities.md`
- `docs/api/trophy-config.md` (2026-10-18) — the `trophy` sprite config that
  **replaced** `trophyImage`/`trophyColour` on league and competition payloads:
  shape, the 12-colour ribbon palette, the legacy slug/colour mapping (so a
  client reading cached payloads knows the correspondence), which endpoints
  carry it, and why `null` must not be defaulted into a visible cup — see
  "Trophy Identity" in CLAUDE.md
- `docs/api/setup-tutorial.md` (2026-11-02) — **a work brief, not a contract: nothing in
  it is implemented server-side yet.** The optional `tutorial: {version, items[]}` block
  the app now reads off `GET /api/starter-config` to drive first-run cards inside new-game
  setup: payload shape, the client's per-item drop rules, valid anchors, the five briefings
  and where they anchor, `ngs.tut.*` copy keys, and `version` as the replay lever. Flagged
  here rather than left to be found because its failure mode is **silence** —
  `parseTutorial` cannot throw, so a bad anchor or a missing copy key makes a card not
  appear, with no error anywhere.
  **Two reasons it is listed as an exception.** First, it sits in `docs/api/` against the
  grain of the 2026-11-02 prune above, which deleted
  `tournament-sealed-results-backend-work.md` for being exactly this genre; its own header
  says to rewrite it as a contract describing what IS — and cite it from
  `StarterConfigController`'s docblock, which is what earns a place in that directory — or
  delete it, once the work lands. **So it is prunable the moment it is actioned.** Second,
  it carries an item that is not about this feature at all: `Club::$tutorialCompletedAt`
  and the `tutorialCompleted` audience criterion had been fed by nothing since 2026-09-29,
  the client half is fixed (`wunderkind-app@fe84d02e`) and a one-off backfill off
  `last_synced_week >= 1` is still owed here — read it in
  `src/Service/AudienceCriteriaEvaluator.php:31,77` and `SyncService.php:183`;
  `03_data/output/entities.md` lists the column among `Club`'s fields but does not say
  what it means, and its docblock on the entity is still the pre-2026-09-29 wording
