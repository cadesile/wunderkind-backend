# Current Focus

## In flight, 2026-11-11 → 2026-11-12 — taking world content off the deploy path

One theme across both days: **everything a new country needs, authored from the admin instead
of from a PR.** Adding Ghana was the forcing case — it exposed that a country the place
registry has never heard of could not be seeded at all.

- **Club-naming content** (`864a775` and after) — `prestige_suffixes`, `generic_suffixes`,
  `stadium_formats` on `country_profile`, with an AI suggest. Clears the CONTENT readiness
  blocker, which used to name a 2,364-line PHP file.
- **Ability ranges** — `StarterAbilityRangeService` behind a dashboard button. Fixing this
  surfaced a live bug: the Starter config save wrote the whole `league_ability_ranges` column
  from a grid built only of countries that already have `League` rows, **deleting every
  unrendered country's band on any save**. It merges now.
- **Country profile enrichment** — the `country_profile` row's own fields (bbox, land band,
  reference codes), not just its towns.
- **Town pull for a country with no registry entry** — `resolveCountry()` →
  `resolveAnyCountry()` + `ensureCountryProfile()`, and a prompt that asks for the biggest
  places first when nothing is held rather than for "further" towns beyond an empty list.
- **Player and staff name pools** (2026-11-12) — two json columns, an authoring card and an
  AI top-up, plus the crash they exposed: `generateName()` threw `Unknown nationality` where
  the four sibling generators fell back, so a full `app:pool:warm` reported 39 failures on a
  run that had generated 500 players per country successfully.

**What is left of the PHP-only world data:** places, world region, suffix order and business
cluster in `CountryContentRegistry`. The name pools and the three naming lists have both moved
to a DB-override-over-const layer; nothing was migrated, and the consts remain the fallback so
a fresh database can still name people and clubs.

## Deployed 2026-11-02 — the bracket gap is CLOSED

The two commits this section recorded as waiting are **live on `dev` and `master`**, pushed
2026-11-02 23:51 and deployed in runs `41548564308` (dev) and `41548582109` (prod), 2
migrations executed on each:

- `85372c4` — a competition entry now outlives its club
  (`competition_entrant.club_id` nullable / ON DELETE SET NULL,
  `Version20261102140000`), plus `serializeFixtureSide()` repairing brackets already damaged.
  The app half was already out, so a finished cup reading "To be decided" for every opponent
  was that gap rather than a client regression. **Resolved** — do not re-diagnose it as one.
- `bb607f3` — `app:clubs:purge-spoof` removed from both deploy workflows. Purging is now an
  operator action via the admin's "Clear Spoof Clubs".

There was no sequencing hazard in either direction: the client degraded quietly while the
backend was behind, and resolves now the migration has landed. Full writeups in
`03_data/output/migrations.md` and `04_interfaces/output/services.md`.

Two landing-page features shipped in the same push and are gated on real data, so **both stay
invisible in production until a cup exists** — that is the designed empty state, not a broken
deploy:

- `cbdcaf6` — the `#cup-results` board (`CupResultsBoardService`), hidden with its nav link
  until a cup has a resolved final.
- `2f54476` — the CUPS band in `#telemetry` (`CupTelemetryService`) plus the Table Turnover
  ▲/▼ split (`Version20261102160000`) and `CUP // WINNER` feed lines. The band appears as soon
  as a cup is live or open, which is sooner than the board.

**Remember `wunderkind_test` is not migration-tracked** — see
`01_overview/output/environment.md` before running the suite against this work.

From `.context/stages/02_architecture/output/git-activity.md`'s
`git log --oneline -30` / hotspot analysis:

1. **"Phase 1" Competition framework rollout** — entities → eligibility/
   registration API → round processing/match engines/cron provisioning,
   landing in sequence over recent commits. This is the single biggest
   active area of the codebase right now.
2. **Admin panel polish** — ongoing crash fixes, click-through fixes,
   and menu-ordering changes across `Controller/Admin/*CrudController.php`
   and `templates/admin/*`, paired consistently with test changes.
3. **Match-engine/notification depth pass on Competition (2026-09-19)**
   — `DeterministicEngine` fully replaced with a port of
   `wunderkind-app`'s `ResultsEngine.ts`, plus extra-time/penalty-shootout
   resolution so a knockout fixture can never end level; a new
   `MATCH_NARRATIVE` chain-graph content system
   (`MatchNarrativeGeneratorService`) generates the full match commentary
   server-side; and a first-time push-notification subsystem (FCM via
   `kreait/firebase-bundle`, Symfony Messenger — the first use of
   Messenger in this codebase) fires OS notifications for competition
   round-draws/new-registrants/individual match results and, optionally,
   admin broadcasts. See
   `.context/stages/04_interfaces/output/push-notifications.md`,
   `docs/api/competition-registration-snapshot-v2.md`.

4. **Platform security upgrade (2026-10-21)** — a dependency/runtime pass rather
   than a feature one, and the reason the stack's version numbers moved. PHP
   8.4 -> 8.5 (deployed pinned at an exact `php:8.5.12-fpm-alpine`; local Lando
   capped at 8.5.7 by Lando's own image family) for the 2026-10-21 Core
   advisories, then Symfony 8.0.* -> 8.1.* plus EasyAdmin 5.0->5.7, Twig
   3.23->3.30 and API Platform 4.2->4.4 to clear 43 Composer advisories down to
   zero. Touched almost no application logic — the real work was four
   compatibility breaks the upgrade exposed (PHP 8.5 static opcache failing the
   image build, `ReflectionMethod::setAccessible()` deprecation, Symfony 8.1's
   `runCommand()` trait collision killing the whole suite, and EasyAdmin moving
   `ea-clickable-row` to client-side JS). See `.context/shared/stack.md`'s
   dependency-floor bullet, `01_overview/output/environment.md`'s "Keeping PHP
   and dependencies patched", and root `CLAUDE.md`'s Testing section.

5. **First JSON exposure of the live telemetry aggregate (2026-10-21)** —
   `GET /api/telemetry` (`Api\TelemetryController`,
   `docs/api/live-telemetry.md`). Notable less as a feature than as a pattern:
   the data, its generator command and its 15-min cron had existed for a while
   with **no endpoint at all**, because `LandingController` renders it
   server-side into HTML — so "the service exists" did not imply "the app can
   read it". The endpoint projects rather than dumps the snapshot, stripping
   three stored keys (a persisted `DateTimeImmutable`, a generation-time
   relative string, and the web-only dossier bundle). Same pass suppressed the
   container's version-disclosure headers. Expect more of this shape as the app
   grows: landing-page-only aggregates needing a client contract, where the
   stored shape is not the servable shape.

6. **Authorization contracts the app had to infer from a status code
   (2026-11-01)** — a lockout fix (`ROLE_CLUB` 403s, commit `5333732`,
   `docs/api/role-club-403.md`), and like item 5 it is more interesting as a
   pattern than as a feature. Three separate faults all presented to the app as
   one bare `403`, which it could only read as "this account is refused": a
   role resolved from a column that had held two obsolete values, an endpoint
   whose own 404 was shadowed by its role gate, and a resolver exception that
   is deliberately a 403 everywhere except the one endpoint asking whether a
   thing exists. None of them were visible from outside without a token, and
   the suite had no coverage of the *negative* case — an account that is
   authenticated but not yet entitled — so all three shipped.

   What to expect more of: this API's authorization surface is now broad
   (14 `roles: ROLE_CLUB` rules in `access_control` and 30 `#[IsGranted(
   'ROLE_CLUB')]` attributes in `src/`, counted 2026-11-01) and a single status
   code carries the whole story to a client. Those two layers overlapping is
   itself the hazard — most endpoints are gated twice, so a gate relaxed in one
   place is not relaxed. The generalisable rule from this pass is that **an endpoint whose
   purpose is to answer about absence must not be gated on the thing being
   absent** — and that any such endpoint needs a test fixture representing the
   not-yet-entitled caller, not just the happy-path owner. The two new test
   files (`tests/Entity/UserRolesTest.php`,
   `tests/Controller/Api/ClubCheckAccessTest.php`) store no roles at all for
   exactly that reason. Reported by the app team rather than found here, via
   `wunderkind-app/docs/backend-notes/`, which is becoming the normal inbound
   channel for this class of issue.

Clusters 1-2 are corroborated by migration history
(`.context/stages/03_data/output/migrations.md`: the large Competition
migration is the most structurally significant recent change) and by
stage 04's routes/services findings (the Competition subsystem is the
newest, most internally-consistent module in the interface layer).
