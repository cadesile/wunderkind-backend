# Current Focus

## Shipped inert, 2026-11-12 — the economy overhaul

**Deployed is not live.** Eight migrations landed (`Version20261112160000` through `...210000`)
with every country factor at parity, the wage model on `legacy`, inflation at 0, the turnover
table unauthored and the season-investment ceiling at 0. The deploy was a provable no-op —
verified against a full clone of the dev dataset each step. Turning it on is an operator
procedure: **`docs/deploy/economy-rollout.md`**.

What exists now:

- **`EconomyScaleResolver`** — the ONLY place country and tier compose into money. Integer basis
  points, one division at the end, one `pow()` for the ability curve. Raw row indexing: tier *t*
  reads row *t*, which reversed the old `scaleTierToEight()` stretching (removed).
- **`WageModelResolver`** — replaced FOUR copies of the wage arithmetic, one of which
  (`GenerateMarketDataCommand`) ignored both the live config and the tier multipliers and priced
  players two orders of magnitude low, and left every scout it made on wage 0.
  `legacy` is a supported mode that must stay byte-identical; `WageModelLegacyParityTest` holds
  the pre-unification expressions independently and must never be "refactored" to call the
  resolver.
- **Two populations, two treatments** — the distinction that governs everything: per-country
  world-pack rows are priced at generation and stored (hence the `WORLD_PACK_VERSION` bump at
  flip time), while shared-pool rows are one row served to every country and are priced at
  **serve time**.
- **`LeagueSizeResolver`** — the TV pool's divisor, authored rather than counted, because a
  `League` row is a template shared by every save (England tier 8 had **147 player clubs**
  pointing at one row).
- `app:economy:wage-table` — the gate, five checks, exits non-zero. It has already caught two
  real defects in this work.
- `GET/POST /api/club/season-investment`, World → Economy admin screen, and the verbatim
  parachute passthrough.

**Fixed 2026-11-12:** the youth-scholarship inversion. The flat £250 was 1.97x an average
tier-8 wage under the turnover model; `economyYouthScholarshipCapBp` caps it at a share of the
tier's WORST first-team wage, taking tier 8 to 0.05x while leaving tiers 1-6 byte-identical. A
cap rather than a replacement, because the flat figure was right at six of the eight tiers. It
ships ON at 50% — the one switch in this series that does — and is still a provable no-op,
because it applies only under the `turnover` model.

**Still open, and recorded rather than quietly carried:** `bankruptcyDeductionTiers` has two rows
that cannot fire under the resolution its own docblock documents, and its ladder is traversed in
under half a week at the top five tiers (a client-side mechanic, gated by its own flag, and it
predates this work); and the sponsor bands are still unscaled by country. Both in
**`docs/economy-threshold-review.md`**.


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
