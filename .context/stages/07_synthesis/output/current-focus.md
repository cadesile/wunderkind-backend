# Current Focus

## Fixed 2026-11-17 — staff cost more than the players they coached

Reported from a tier-8 Clydebank save: **staff −£1,000/week against −£165/week for the entire
squad**. Two independent causes, both pre-existing and neither created by the set work — it
merely made them visible.

1. **Staff ability came from one global range** (`PoolConfig::$coachAbilityMin/Max`, 4-75 on
   dev) regardless of tier, so a local club could field a world-class manager — and ability
   drives `staffMultiplier`, 2.0 up to 12.0, so that alone was a 6x swing in pay. Players never
   had this problem; their band has always been per-tier. `StaffGenerationService::build()` now
   takes the club's band, and every per-club caller passes the SAME array its players came from.
2. **Legacy staff pay had no tier dimension at all.** The role bands are single absolutes, so
   they can only be right at one tier, while a legacy player's wage scales with a per-tier
   ability. `staffWeeklyPence()` now scales the band by `Economy\TierBandScaleResolver` —
   `midpoint(tier) / midpoint(tier 1)` over the already-authored `league_ability_ranges`, which
   is the only tier ladder that exists under `legacy` and is the same axis players scale on.

EN after the fix: staff average £1,116/wk at tier 1, £240 at tier 4, £18.88 at tier 8; a
founder's staff total £233/wk against £4,852/wk of players.

**`TierBandScaleResolver` exists because `EconomyScaleResolver` cannot be used here** — it
resolves from authored turnover and returns `isResolvable === false` on every deployed box, so
everything built on it is inert under the model every box is actually running.

Three things to carry forward:

- **The scaling is applied to the BAND, never inside `legacyStaffPence()`**, which
  `WageModelLegacyParityTest` pins byte-for-byte. That test passes unmodified, which is the
  proof `legacy` still means what it meant.
- **`WORLD_PACK_VERSION` went 7 → 8** in the same commit, because `buildStaffSnapshot()` bakes
  `weeklySalary` into every pack. Every set became unclaimable at once; `app:worldpack:maintain`
  restocks live countries without an operator.
- **The kill switch ships ON**, against this repo's ship-inert habit, because the old behaviour
  is wrong rather than untuned — and it stays a column because the figure is cached.

**Still open:** the overall staff SHARE of a wage bill is a separate calibration question this
does not answer. At tier 1 staff now come to ~2.4% of a player's wage and at tier 8 ~6.6%, so
the ladder is sane and slightly favours the bottom, but whether those shares are right is the
turnover model's business (`economyStaffWageShareBp`), not legacy's.

## Landed 2026-11-17 — world packs become single-use SETS

**Every world a player is given is now unique, and it can no longer exist without a squad.**
A `WorldPackSet` is one starter squad plus a payload per league tier, generated together,
claimed by exactly one club at `POST /api/initialize/starter`, and never served twice. Each
live country keeps **two** claimable sets (`StarterConfig::$worldPackSetTarget`); every game
initialisation claims one and queues a replacement, with `app:worldpack:maintain` sweeping
hourly as a backstop.

What it fixes, both of which were real rather than theoretical:

- `country_world_pack_cache` was keyed `(country, tier)` with no owner, so every player in a
  country got the byte-identical world.
- The starter squad was a separate live draw that could fail `412` against a country whose
  packs were perfectly warm — a gap between "the world exists" and "a squad to play it with
  exists", at the first step of game init, with no client-side escape.

**No API shape changed, and `WORLD_PACK_VERSION` stayed 7 for the set work itself** (it moved to 8 later the same day for the staff-pay fix below). Two values moved under unchanged
keys (packs are now per-device-distinct, and a player's own tier's `fixtures` no longer carries
their club id); both are logged in `shared/api-backwards-compatibility.md` with their consumer
checks, the second one definitive — the app types that field as not consumed and generates its
own schedule. The compatibility property the whole change rests on is the **fallback**: a
country with no stock behaves exactly as before, live draw and an uncached inline tier build.
No new error codes, deliberately.

Three things to know before working on it:

- **`WorldPackCacheService` is entirely set-scoped and the old `(country, tier)` lookups are
  deleted, not deprecated.** `findOneBy(['country','tier'])` would return an arbitrary one of a
  country's sets, and the damage would be silent — tier 1 of one world and tier 2 of another,
  every response a 200.
- **Top-ups do not go through `WorldPackGenerationRun`.** That table is single-flight per
  country and a top-up fires on every init, so they build inline on their own `worldpackset`
  transport instead. Guards: a dispatch-side gate, a deficit re-read on drain, and a
  per-country `pg_try_advisory_lock`.
- **`app:worldpack:warm` now means "rebuild this country's buffer"** and `--tier` is a hard
  error: a set has to be whole to be servable. `app:worldpack:topup` adds one without clearing.

**And the squad is GENERATED, not drawn from the pool** — the change that removed the pool
from this picture entirely. `StarterPackService::generatePack()` builds the founder's players,
staff and scouts through `WorldPackClubGenerationService`, the same generator every NPC club
goes through, so a set consumes nothing (verified: two full EN sets left `player`/`staff`
counts unchanged) and can never be short. `InsufficientStarterPoolException` and the
`MIN_POOL_SIZE` 412 are both unreachable — **a new game can no longer be refused for a thin
pool**, which was the one failure in that flow with no client-side escape.

**One deliberate balance change rides with it, and it is not gated on the wage model.** The
tier band is a POTENTIAL range (`PlayerGenerationService::generate()`'s parameter is
`$potentialRange`; ability is derived below it). The old draw filtered on `currentAbility`, so a
drawn founder had its ABILITY in the band while its opponents had their POTENTIAL in it —
founders were systematically stronger than the tier they were placed in. EN tier 8 now reads
starter ability 1-3-5 / potential 2-7 against NPC ability 1-3-6 / potential 2-7. Logged in
`shared/api-backwards-compatibility.md`.

**Economy coverage closed at the same time.** `Economy\ServeTimePlayerPricer` now owns the
"shared-pool rows are priced at serve time" rule, which previously lived inside
`PlayerBrowseSerializer` and so reached only the two endpoints using it —
`GET /api/players/youth` serialized through `PlayerSnapshotSerializer` and served the stored
legacy figure. With the founder's squad priced at generation and youth priced at serve, no
player surface is outside the model.

**Still open — and the aim is now wrong rather than the code:**
`StarterPackDiagnosticService`, `StarterPoolAuditService`, `app:starter-pack:check` and
`app:starter-pool:audit` all turn on "can a starter pack be drawn for this country", which is
now always yes. They were **deliberately not deleted**: they are the only SCHEDULED thing that
tops the shared pool up at all (`app:pool:warm` is the general warmer and remains unscheduled),
and the pool still feeds youth, foreign, scout search and market data. Deleting them would have
quietly starved those four. Their floors and triggers need re-deriving from what actually
consumes the pool now. Until then they over-queue rather than under-, which is the safe
direction.

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

**Fixed 2026-11-12, then fixed properly 2026-11-13:** the youth scholarship was wrong at BOTH
ends of the turnover ladder. The flat £250 was 1.97x an average tier-8 wage *and* 0.19% of an
average tier-1 wage — a single absolute figure cannot be right across a 1,047x spread.

Pass 1 (`economyYouthScholarshipCapBp`, ships ON at 50% — the one switch in this series that
does) capped the end that inverted and therefore never looked at tier 1. Pass 2
(`economyYouthScholarshipTier1Pence` + `…FloorPence`, both default 0 = off) makes it an anchored
ladder: author what a scholar costs at tier 1, every lower tier is the same proportion of its own
average wage, floored so it does not decay to £1.43, capped last so the invariant always wins.
The cap's anchor moved from the tier's WORST wage to its AVERAGE, because tier 8's cheapest
professional earns £12.84/wk and half of that is a free scholar, not a cheap one. Still a
provable no-op: everything applies only under `turnover`.

**Found by running the gate, not by reading it** — which also turned up a defect in the gate
itself, reporting a scholarship problem that no authoring action could clear. See
`docs/economy-threshold-review.md` §2 and §2b.

**Calibrated on dev 2026-11-13, model still OFF.** The turnover ladder and the scholarship anchor
are authored (both gated on `economyWageModel`, so no price moved — verified by byte-identical
checksums on player, staff, scout, npc_club and all 200 pack payloads). The TV table was
re-authored across all 196 leagues, and **that one DID move a published value**: `league.tv_deal`
is a stored absolute with no model gate, and the old figures were 102%-3,674% of a club's season
turnover. Tested on a real device against the shipped app afterwards: the economics are
noticeably more stable, which is direct evidence the old TV figures were breaking client finances
rather than merely failing a gate.

Two things came out of it. **`league.tv_deal` is the one money figure the country factor never
reaches** — now guarded by the gate's check 6 (`c6fba41`), because check 3's 100%-of-turnover
bound cannot see a country at twice its intended share. And **cached packs bake `tvDeal` while the
version gate only moves when code does**, so 60 of 200 packs are silently stale; a rebuild is with
the operator. The rollout doc's own derivation method was also wrong — it specified a geometric
turnover ladder, which fails checks 1 and 2 against real bands; `S_tier` is the right primitive
and makes both checks structural.

**Also fixed 2026-11-12:** the sponsor bands scale by country, so a cheap country is cheap on
both sides of the ledger rather than earning full English sponsorship on a fifth of the wages.
Country factor ONLY, not country x tier — a league's tier variation is already expressed by its
`sponsorCount` and size mix. It collapsed a FIFTH byte-identical duplication on the way.

**Still open:** `bankruptcyDeductionTiers` has two rows that cannot fire under the resolution its
own docblock documents, and its ladder is traversed in under half a week at the top five tiers.
That is a client-side mechanic gated by its own flag, and it predates this work — see
**`docs/economy-threshold-review.md`** for the analysis and the weeks-of-wages proposal.


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
