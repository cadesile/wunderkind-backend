# Last Sync

> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Read by the
> session-start staleness check (see `SKILL.md`'s Triggers table) to find
> commits that have landed since `.context/` was last reviewed.

- **New pass (2026-10-24), on top of commit 883f731** (uncommitted).
  **Stages touched:** 03_data (`entities.md`, `migrations.md`), 04_interfaces
  (`services.md`, `routes.md`). Backend half of the production Sentry sweep
  (`docs/2026-10-24-sentry-sweep-backend-brief.md`; findings and answers in
  `docs/2026-10-24-sentry-sweep-backend-answers.md`). Every item exists because the
  client gained a retry queue and started treating an empty/truncated 2xx as retryable,
  so requests that were never safe to retry now are:
  1. `ApiExceptionSubscriber` — every `/api/*` error is `application/problem+json` with
     `violations[]`, regardless of `Accept` (it was Symfony's HTML error page).
  2. `LeagueService::concludeSeason()` idempotent per club+season — `UNIQUE(club, season)`
     on both archive tables, first response stored on `SeasonSnapshot::$responseJson` and
     replayed, `currentSeason` advanced only for the current season; new optional
     `ConcludeSeasonRequest::$season`.
  3. `POST /api/initialize/starter` replays `Club::$starterPackJson` instead of 409 (the
     pack was previously unrecoverable once the response was lost).
  4. `WorldPackCacheService::getOrBuild()` serializes a cache miss behind a Postgres
     advisory lock and re-checks under it.
  5. `IdempotencySubscriber` allowlist adds `/api/club/initialize` and
     `/api/league/conclude-season`.
  6. `OwnerAvatarRequest::$dob` accepts an ISO-8601 datetime, and `""` clears rather than
     meaning today.
  **Unactioned findings** (deliberately left for the human — production data/ops
  decisions, see the answers doc): `StarterConfig.npcSquadConfig.coachCount` is 7–13 per
  club against a code default of 1, inflating NPC staff 7–13×; `app:worldpack:warm` is not
  in the deploy sequence and JP/US/NG/CA have only tiers 1–2 cached; JP is not in
  `enabledCountries` yet clients are initializing into it.

- **New pass (2026-10-24), on top of commit 68331a5** (uncommitted).
  **Stages touched:** 03_data (`entities.md`, `migrations.md`), 04_interfaces (`services.md`).
  1. Aligned the competition & friendly snapshot pipeline with `wunderkind-app`'s `TournamentSnapshotBuilder.ts` (`docs/api/competition-registration-snapshot.md`) and `docs/api/match-detail-club-fields.md`: added `CompetitionRegistrationService::normalizeSnapshotClub()`, `normalizeClubData()`, and `formatLineupForClient()`, preserved `players[].appearance` in `DeterministicEngine::calculatePerformance()`, explicitly validated `playingStyle` against `PlayingStyle`, omitted `stadiumName` and `playingStyle` (rather than emitting `null`) when unset, merged entrant `snapshotJson['club']` + `Club` entity fallbacks under frozen `homeClubJson`/`awayClubJson` on completed matches, and returned `identity`, `stadium`, `stadiumName`, `playingStyle`, `reputation`, `tier`, `badgeShape`, kit/badge configs, and lineup `appearance` across all competition, match, and friendly endpoints.
  2. Added engine-agnostic match derivation & computation auditing (`MatchEngineResult::$computationAudit`, `CompetitionResult::$computationAuditJson`, migration `Version20261024012500`): `DeterministicEngine` records the full step-by-step computation breakdown (`dominance`, `regulation`, `extraTime`, `penaltyShootout`, `goalDistribution`, `cardDistribution`, `playerRatings`, `outcome`) without altering the RNG sequence, `AiEngineStub` records delegation/fallback metadata, `CompetitionResultsService::buildComputationAudit()` guarantees an audit envelope regardless of engine and stamps round/winner/tiebreak context, and `templates/admin/competition/active_competition_detail.html.twig` renders the audit table and raw JSON in the admin detail view while keeping it out of client payloads.

- **New pass (2026-10-23), on top of commit 68331a5** (uncommitted).
  **Stages touched:** 03_data (`entities.md`, `migrations.md`), 04_interfaces (`routes.md`, `controllers.md`). Implemented the 7-milestone Tournament / Competition API overhaul & Friendlies Challenge Board (`docs/superpowers/plans/2026-10-22-tournament-and-friendlies-api.md`):
  1. Enriched competition club identity payloads (`clubId`, `name`, `abbreviation`, `ovr`, `reputation`, `reputationTier`, `homeKitConfig`, `awayKitConfig`, `badgeConfig`), fixed `CompetitionSpoofEntrantService` to emit valid `BadgeShape` cases (`round`/`shield`, not `circle`/`badge`), and added `viewer` block + `serverTime` across competition reads.
  2. One-off `entryFeePence` atomic deduction on `POST /api/competitions/{id}/register` with `insufficient_funds` eligibility check, `POST /api/competitions/{id}/withdraw` (`CompetitionEntrantStatus::WITHDRAWN` excluded from `registeredCount`, refunds `entryFeePence`), and automatic `entryFeePence` refund on competition cancellation.
  3. Full bracket rounds (`rounds[]` always contains `log2(entrantCapacity)` rounds with `TBD` placeholders, `bracketSize`, `byeCount: 0`, `champion`, `ETag` 304 support) and first-class Match & H2H endpoints (`MatchQueryService`, `MatchController`: `GET /api/matches/{matchId}`, `GET /api/club/matches?state=upcoming|recent`, `GET /api/club/h2h/{opponentClubId}`).
  4. Required `X-Club-Id` (`ClubResolver::resolveRequiredFromRequest()`, `400 club_id_required`) on competition/friendly writes, stale `snapshotLockedAt` check (`409 round_already_drawn`) on resubmit, and 24h `Idempotency-Key` support (`IdempotencyRecord`, `IdempotencySubscriber`).
  5. Hardened `/api/inbox` (`MessageStatus::DISMISSED`, `POST /api/inbox/{id}/dismiss`, `type`/`link`/`requiresAccept` fields, single combined `COMPETITION_WON` reward message in `RewardApplierService`, `409 already_processed` on repeat accept, and persistent inbox notifications on tournament lock, draw, and match resolution).
  6. Friendlies Challenge Board (`FriendlyChallenge`, `FriendlyChallengeService`, `FriendlyController`, `DeterministicEngine::resolveFriendly()`, `app:friendly:resolve-matches`, `app:friendly:expire-challenges`) with reputation-tier + stake filtering, home/away venue selection, stake escrow/payout, and migration `Version20261023133500`.
  7. Added `GET /api/competitions`, `GET /api/competitions/champions`, `GET /api/club/competitions`, and `GET /api/club/hub` (`ClubHubController`).

- **Doc-only pass (2026-10-21), on top of commit 68331a5** (uncommitted).
  **Stages touched:** 01_overview, 02_architecture, 04_interfaces, 07_synthesis,
  plus `shared/stack.md`. The human spotted that the new telemetry endpoint's
  documented base URL was wrong — and the root cause was a `.context/` gap: the
  public hostnames were recorded NOWHERE in `.context/`, only in
  `deploy/proxy/Caddyfile` and a comment in `docker-compose.dev.yml`. Caddy serves
  **four** hostnames — prod `buildmyclub.co.uk` + `www.` + **`api.`**, dev `dev.` +
  **`api.dev.`** — all reverse-proxied to the same container with **no path
  rewriting**, so the `api.` hosts (the intended client entry points) still require
  the `/api` prefix: `api.buildmyclub.co.uk/api/telemetry` is 200,
  `api.buildmyclub.co.uk/telemetry` is 404 (both verified live). Recorded in
  environment.md, structure.md's proxy bullet, routes.md's header (so every path in
  that file has a stated host), stack.md and the synthesis overview, and added as a
  Base URL table to `docs/api/live-telemetry.md`. No code changed.

- **New pass (2026-10-21), on top of commit 0f1e0c3** (uncommitted).
  **Stages touched:** 01_overview, 02_architecture, 04_interfaces (routes.md,
  controllers.md, services.md), 06_documentation, 07_synthesis. Added the public
  `GET /api/telemetry` endpoint (`Api\TelemetryController` +
  `docs/api/live-telemetry.md` + 6 functional tests), exposing the existing
  `LiveTelemetrySnapshot` aggregate as JSON for the React Native app — scoped by the
  human as public / scalars+feed / no dossier. Not a verbatim dump of the snapshot:
  the stored feed items carry `at` (a real `DateTimeImmutable` persisted into the json
  column, which json_encodes to a PHP `{date,timezone_type,timezone}` artifact), `time`
  (a relative string frozen at generation, so stale on arrival and permanently frozen in
  a client cache) and `dossier` (web boardroom-overlay bulk) — all three stripped, with
  tests asserting they never reach a client. `capitalDeployedPence` is cast because the
  `bigint` column hydrates as a string. Note `attachClubDossiers()` runs inside
  `refresh()`, so dossiers ARE persisted — excluding them is a serializer strip, not an
  avoided query. Known gap left deliberately: `amountExact` is a pre-formatted GBP
  string and the raw pence is not stored per event, so a client cannot localise it;
  fixing that needs a generator change + snapshot rebuild.
  Same pass also suppressed the deployed container's version-disclosure headers
  (`expose_php = Off` in a new `docker/php.ini`, `server_tokens off` in
  `docker/nginx.conf`) — every response had been publishing `PHP/8.5.12` and
  `nginx/1.30.4`. Verified from a real booted container: `X-Powered-By` gone
  entirely, `Server` reduced to a bare `nginx`. Local Lando is unaffected (different
  images, neither reads these files). Updated `01_overview/output/environment.md` and
  `02_architecture/output/structure.md` for both.

- **Doc-only pass (2026-10-21), on top of commit 0f1e0c3** (uncommitted).
  **Stages touched:** 04_interfaces. Scoping a telemetry endpoint for the React
  Native app surfaced two gaps in `04_interfaces/output/routes.md`: the
  `DashboardController` bespoke-route list omitted `/admin/telemetry-config` and
  `POST /admin/telemetry-config/rebuild`, and nothing recorded the **negative**
  fact that there is no `/api/telemetry` route at all — `LiveTelemetryService`,
  `LiveTelemetrySnapshot`, a generator command and a 15-min cron all exist, so the
  natural assumption is that an endpoint does too. `LandingController` (Twig) is
  its only consumer. Both now stated in routes.md. Also corrected a **stale** fact
  in `04_interfaces/output/services.md`: it claimed some `LiveTelemetryService`
  feed entries (sackings, contract disputes, youth intake) are illustrative with no
  backing data. Those six static lines were removed by product decision and "real
  events only" is now a binding constraint on every category — the note said the
  opposite of the rule. No code changed.

- **New pass (2026-10-21), on top of commit c8f4d80** (this pass's own changes
  are uncommitted on top of it). **Stages touched:** 01_overview, 02_architecture,
  07_synthesis, plus `shared/stack.md`. A security-patching pass, user-initiated
  off the 2026-10-21 PHP advisories (GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp).
  Two separate pieces of work: (1) **PHP 8.4 -> 8.5**, deployed images pinned to an
  exact `php:8.5.12-fpm-alpine` instead of a floating tag, local Lando moved onto
  `devwithlando/php:8.5-fpm-7` (PHP 8.5.7) via an `appserver` image override
  because Lando 3.25.6's `symfony` recipe rejects `php: '8.5'` outright — so local
  dev now deliberately trails deployed by five patches, recorded in `.lando.yml`'s
  own comments. (2) **43 Composer advisories -> 0**, which required Symfony
  8.0.* -> 8.1.* (not optional: Composer's advisory policy made the entire 8.0
  branch unloadable), dragging EasyAdmin 5.0.1->5.7.0, Twig 3.23->3.30, API
  Platform 4.2.19->4.4.5, PHPUnit 13.1.1->13.4.3. Four real breaks found and
  fixed, each verified rather than assumed: PHP 8.5's statically-compiled opcache
  failing `docker build` (`cp: can't stat 'modules/*'`), the
  `ReflectionMethod::setAccessible()` deprecation (3 call sites), Symfony 8.1's
  new static `KernelTestCase::runCommand()` colliding with a test helper and
  killing the whole suite at exit 255, and EasyAdmin moving `ea-clickable-row`
  from server-rendered markup to runtime JS. Also fixed a pre-existing unrelated
  breakage: `lando composer` had no composer binary in the image at all. Updated
  `shared/stack.md` (framework/PHP versions + a new dependency-security-floor
  bullet), `01_overview/output/environment.md` (Lando PHP override, opcache trap,
  new "Keeping PHP and dependencies patched" section),
  `02_architecture/output/structure.md` (Dockerfile pin + opcache), and
  `07_synthesis/output/current-focus.md` (new cluster 4).

- **New pass (2026-10-20), on top of commit bf037a2** (this pass's own changes
  are uncommitted on top of it). **Stages touched:** 05_ui. Took the v2 facility
  sprite drop (`assets/Components/facilitiesV2/facilities/`, dated 2026-10-20)
  and rebuilt the landing page's stadium render on it, per explicit user
  request. `public/assets/stadium-compositor.js` was the last hand-ported
  compositor — viable when v1's `isoEngine.ts` was ~19KB and only the helpers
  the stadium touched had to be transcribed, not against v2's ~32KB engine (new
  `paintPixels` lighting, shared massing, five tree kinds) and its 301 pixel
  hashes. It is now GENERATED by a new `scripts/build-stadium-compositor.sh` +
  `scripts/stadium-compositor/entry.ts`, the fourth sibling of the avatar/kit/
  trophy build scripts, parity-gated on **all four** sites in the drop (301/301
  matching) even though only the stadium is bundled, since `isoEngine.ts` is
  shared. The `look="v1"` legacy tree is neither checked nor shipped. Visible
  changes: the image grows 276x216 -> 324x240 (so `.spotlight-stadium svg`'s
  pinned height goes 160px -> 178px, the same ratio, to keep the bowl itself the
  size it has always been rather than ~10% smaller), cast shadows and window
  highlights appear, and trees are mixed and jittered rather than a grid ring. `ClubSpotlightService::buildStadiumConfig()` now doubles the
  shop/museum/car-park levels as it already did the stands — v2 redrew those
  three for 10 levels, so the old raw 1-5 passthrough would have halved them —
  and `landing_v` is bumped to `20261020` so the cached old bundle cannot
  survive the deploy. Updated `05_ui/output/design-system.md` (the "three
  generated compositors, stadium predates the rule" bullet is now four, plus
  two new bullets on the multi-file drop and the 1-5 -> 1-10 level mapping).
  Full suite green (1281 tests), including two new/updated assertions in
  `ClubSpotlightStadiumConfigTest`. **Not done, deliberately:** the other three
  facility sites (training ground, medical centre, scouting) are still unused
  backend-side, and the drop's `selected` dimming option is forwarded but unused
  — it mixes toward `BACKDROP[time]`, which the spotlight card's green pitch
  band is not.

---

- **New pass (2026-10-18), on top of commit 8eae79a** (this pass's own changes
  are uncommitted on top of it). **Stages touched:** 03_data, 04_interfaces,
  05_ui, 06_documentation. Replaced the trophy SVG system with a sprite
  component matching the avatar and kit/badge ones, per explicit user request.
  `League` and `CompetitionTemplate` each dropped `trophy_image` (a
  `trophy-1`..`trophy-15` slug naming a static black silhouette in
  `public/images/trophies/`) and `trophy_colour` (`App\Enum\TrophyColour`,
  applied as a CSS filter chain duplicated across the admin leagues screen and
  the landing honours board) for a single nullable `trophy` JSON column holding
  the sprite's own `TrophyConfig` — migration `Version20261018202041`, which
  **converts** rather than resets (finish maps 1:1 bar `gold_silver` →
  `twotone`; the 15 slugs spread across the 10 new styles in order, there being
  no artwork correspondence). New `TrophyStyle`/`TrophyFinish` enums in
  `src/Enum/Appearance/` (ribbon colours reuse `KitColor`, the sprite's ribbon
  palette being the shared kit palette), new all-static `TrophyConfigFactory`
  (defaults/validate/legacy mapping — the single validator in front of admin
  posts, the world import and the backfill), new `TrophyConfigType` admin
  widget + form theme + `trophy-config-widget.js`, and a new GENERATED
  `public/assets/trophy-compositor.js` built from the shared
  `assets/Components/trophy16/trophy16.ts` by
  `scripts/build-trophy-compositor.sh` — parity-gated on that sprite's own
  pixel-hash fixture (24/24 cases matching), the third sibling of the avatar
  and kit build scripts. `GameConfig::leagueTierDefaults` rows now normalise
  through `normaliseTierRow()` on read and write so stored JSON and old config
  export files keep their trophies. Updated
  `03_data/output/{entities,migrations}.md`,
  `04_interfaces/output/services.md`, `05_ui/output/design-system.md` (the
  "keep two filter chains in step" rule is gone, replaced by a note on the
  three generated compositors), `06_documentation/output/index.md`. New client
  contract doc `docs/api/trophy-config.md`. Full suite green (1278 tests) bar
  one pre-existing unrelated failure
  (`AdminStatsControllerTest::testLeaderboardsCoverEveryCategoryCappedAtTen`,
  drift from the two preceding leaderboard-kit/flag commits — confirmed failing
  identically on a clean `8eae79a` checkout). New coverage:
  `TrophyConfigFactoryTest` (32 tests, incl. 4 that read the generated bundle's
  own exported vocabulary via node to catch PHP↔sprite drift, skipping where
  node is absent as it is inside Lando) plus two world-pack trophy assertions
  in `WorldInitializationTierPackAgentTest`.

---

- **Commit:** 570d2d7 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request). **Stages touched:** 03_data, 04_interfaces (two linked changes
  in one session — adding America/Canada/Japan/Nigeria as generation-capable
  countries, and a repeatable "add a country" process, per explicit user
  request):
  1. `App\Enum\Country` gained `US`/`CA` cases (`isGenerationCapable()` now
     covers 13 countries: the original 9 plus US/CA/JP/NG) and two new
     helpers, `fromNationality()`/`labelMap()`. New
     `App\Service\CountryContent\CountryContentRegistry` consolidates what
     used to be 4 independently-drifting per-country consts
     (`NpcClubGenerationService`'s place/suffix/stadium data,
     `WorldRegion::NATIONALITY_REGIONS`, `MarketPoolService::NATIONALITY_MAP`)
     into one file keyed by `Country` — `NameGeneratorService`'s actual name
     pools stay where they are (too large to move safely) but are now keyed
     off `Country::nationalityMap()` via `NameGeneratorService::nationalities()`
     instead of a 4th hand-duplicated list; only `'Polish'` (no `Country`
     case) remains a standalone addendum in both. New `WorldRegion::
     NORTH_AMERICA` case. Authored real content (places/suffixes/stadium
     formats, American/Canadian name pools, business cluster) for all 4 new
     countries via web research. Two new commands,
     `app:country:bootstrap`/`app:country:check`, replace what used to be
     1 admin click + 8 admin clicks + 2 separate CLI commands with one
     command plus a read-only audit. Fixed the 7 previously-hardcoded
     admin-template country/nationality lists (2 generate-screen dropdowns,
     the enabledCountries checkboxes, 2 display-only country-label maps, 2
     nationality chip lists) to read from `Country`/`NameGeneratorService`
     dynamically, closing the exact drift class documented in
     `Country`'s own docblock and
     `docs/superpowers/specs/2026-07-06-club-name-options-canonical-source-design.md`.
     New runbook: `docs/world-generation/adding-a-country.md`.
  2. Separately-requested: a new admin "League Tier Defaults" bulk-edit
     screen on `admin_leagues_overview` (one row per tier, shared across
     every country — promotion spots, TV deal, prize money, position pot,
     sponsor count, trophy design/colour) that writes to new
     `GameConfig::$leagueTierDefaults` (json, keyed `"1"`-`"8"`) — the new
     single source of truth `LeagueService::generateLeaguesForCountry()`
     reads, replacing the old hardcoded `LEAGUE_TIER_DEFAULTS` const. A
     league-picker (checkbox-per-league + "select all") lets the same submit
     cascade the new values onto selected **existing** `League` rows too
     (`LeagueAdminController::bulkEditTierDefaults()`, new route
     `admin_league_tier_defaults_bulk_edit`). "Relegation spots" was
     explicitly dropped from scope per the user — the backend has no stored
     relegation count at all, only `promotionSpots` (relegation is decided
     by client-reported flags). Per-country league cards on that same page
     now default-collapsed (new Expand/Collapse-all controls) so the new
     form isn't buried.
  Migration `Version20261004085641` (the auto-generated `doctrine:migrations:diff`
  also surfaced a large amount of pre-existing, unrelated schema drift —
  DROP DEFAULT clauses, index renames, and critically DROP INDEX on raw-SQL
  partial unique indexes not representable in ORM metadata, see CLAUDE.md's
  Testing section — none of that was included; the migration was hand-trimmed
  to just the one `game_config.league_tier_defaults` column). `wunderkind_test`
  needed the same manual column add documented in CLAUDE.md's Testing
  section (schema:update's own diff would have dropped those same unrelated
  partial indexes, so it wasn't used — the column was added directly via
  `lando psql -d wunderkind_test`). Full suite green (1108 tests) after.
  CLAUDE.md's Architecture section gained a new "World Generation:
  Countries & League Tier Defaults" subsection; Key Services/Key Entities
  tables updated to match.

---

- **Commit:** feff081 (as of writing — this pass's own changes are uncommitted
  on top of it). **Stage touched:** 03_data (sync v2: nullable
  `player_career_stat.appearance_config` and new `staff_career_profile` table
  — see `entities.md`'s `PlayerCareerStat`/`StaffCareerProfile` entries,
  `schema.md`'s table index, `migrations.md` entry 25. New
  `SyncRequest::$staffStats` field and `SyncService::processStaffCareerProfiles()`.
  CLAUDE.md's new "Sync v2: In-Club Player/Staff Appearance" section explicitly
  distinguishes this client-authoritative data from the backend-generated
  Avatar Appearance system above it. Covered by
  `tests/Service/SyncServicePlayerStaffStatsTest.php`. No new docs/api/*.md —
  the contract is already documented frontend-side per the request
  (`docs/api/sync-v2.md` in `wunderkind-app`), backend is just conforming to
  an already-agreed spec here.)

---

- **Commit:** fa58cd2 (development/dev/master, pushed). **Stage touched:**
  01_overview (`environment.md` — documented `scripts/pull-prod-db.sh`,
  the reusable production-DB-pull-and-restore-locally workflow, plus a
  `CONTEXT.md` routing-table row mapping the human's "pull prod DB" trigger
  phrase directly to running it — explicit instruction, per the human, to
  run it rather than just describe it when that phrase is used. Raw/
  unscrubbed PII handling was an explicit human decision made via
  `AskUserQuestion` when the script was built this same session, recorded
  here so a future session doesn't second-guess or silently "fix" it.)

---

- **Commit:** b153556 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request, same as the UserLedger pass below it this stacks on top of).
  **Stage touched:** 04_interfaces (`controllers.md` — admin User edit page
  now shows a per-club summary panel: kit/badge previews, last sync/league
  position/form, earnings vs. dividends drawn, and the user's overall
  cross-club balance. `UserCrudController::edit()` override +
  `admin/user_edit.html.twig`; extracted `resultBadge` macro from
  `club_profile.html.twig` into `admin/_macros.html.twig` for reuse; new
  `SyncRecordRepository::findLatestValid()` /
  `UserLedgerRepository::getTotalDividendsByClub()`. CLAUDE.md's Centralized
  User Ledger section updated to match. Covered by
  `tests/Controller/Admin/UserCrudControllerTest.php`.)

---

- **Commit:** b153556 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request). Per the staleness check, `.context/` was 3 commits behind HEAD
  going into this session (`b153556` dedupe squad bonds / resolve player
  names, `2a1c108` positive bonds + telemetry feed categories, `ea2c2dd`
  tiered excursion cost/effect progression) — the user chose to proceed
  without reviewing those first, so they remain **unreflected** in
  `.context/` and are a follow-up, same as the admin club-profile/landing-
  page gap noted in the entry below from 2026-09-28.
- **Date:** 2026-10-02
- **Stages touched this pass:** 03_data, 04_interfaces (new `UserLedger`
  entity/`user_ledger` table — a centralized, cross-club financial audit
  trail for dividend draws; see `entities.md`'s `UserLedger` entry,
  `schema.md`'s `user_ledger` constraint note + the `dividend_draw`
  `ledger[].category` addendum, `migrations.md` entry 24, and
  `services.md`'s `UserLedgerService` entry. New read-only
  `UserLedgerCrudController`, documented in `controllers.md`/`routes.md`.
  Also widened `LiveTelemetryService::ledgerAmountToPence()` from private
  to `public static` so `UserLedgerService` could reuse the existing
  100x-pence correction instead of adding a third implementation — see
  `schema.md`'s "`sync_record.payload` field conventions" section.)
  CLAUDE.md's Architecture section synced with a new "Centralized User
  Ledger" subsection; Key Services/Key Entities tables updated to match.

---

- **Commit:** df082fe (development, merged into dev)
- **Date:** 2026-09-28
- **Stages touched this pass:** 01_overview (corrected the git branching
  policy in `output/deployment.md`, and synced `CLAUDE.md`'s Git Workflow
  section to match, per explicit user correction: `development` is the
  working branch for most tasks — commit directly there, it's a direct
  child of `master` — not a mandatory feature-branch + PR workflow as
  previously documented. `dev`/`master` are still only ever reached by
  merging `development` in, propagated as two separate merges in that
  order. Also recorded as a standing feedback memory outside this repo, as
  a backstop in case this doc drifts again.)

---

- **Commit:** 5cf9851 (also merged into `dev` and `master` this pass — see
  the merge-tagged entry immediately below)
- **Date:** 2026-09-28
- **Stages touched this pass:** 03_data, 04_interfaces (targeted fix + doc
  pass, not a full stage regen — `.context/` was ~28 commits stale going
  into this session per the staleness check, including two admin
  club-profile commits (`2cb67c4`, `aa07cb9`) and the landing-page
  Boardroom Incident Feed (`0ebf4e4`) that `04_interfaces/output/
  controllers.md` still doesn't document; flagged to the user as a
  follow-up rather than regenerated unprompted). Fixed a real financial
  display bug found via user-supplied evidence (in-game ledger screen vs.
  admin club profile vs. raw sync payload for the same events, all three
  compared side by side): `payload.ledger[].amount` is 100x true pence, not
  real pence as every consumer assumed — confirmed by cross-referencing
  `excursions[].costPence` against its matching `fan_initiative` ledger
  line (always exactly 100x). Added `LiveTelemetryService::
  ledgerAmountToPence()` (corrects `capitalDeployedPence` and
  `buildLedgerEvents()`'s `amountPence`) and the `ledgerCurrency()` Twig
  macro (corrects `templates/admin/club_profile.html.twig`'s Ledger table,
  which previously ran raw `entry.amount` through the pence-only `currency()`
  macro). `LiveTelemetryServiceTest` updated for the corrected values (44
  tests green). Documented the convention in `03_data/output/schema.md`
  (new "`sync_record.payload` field conventions" section) and linked it
  from `entities.md`'s `SyncRecord` bullet and `04_interfaces/output/
  services.md`'s `LiveTelemetryService` bullet.

---

- **Commit:** 160ac7a (development, merged into dev)
- **Date:** 2026-09-28
- **Stages touched this pass:** 02_architecture (World Pack Cache generation
  rewritten to generate every club's players/staff/scouts fresh, in-memory,
  rather than drawing from the shared pool — new `WorldPackClubGenerationService`,
  a tracking schema (`WorldPackGenerationRun`/`TierRun`/`ClubRun`, migration
  `Version20260928151627`), and Messenger-driven tier/club batching
  (`WorldPackGenerationOrchestrator`, `WarmWorldPackTierMessageHandler`,
  `WarmWorldPackClubMessageHandler`, `WorldPackTierAssemblyService`); admin UI
  (`worldpack_cache.html.twig`) reworked to trigger regenerates and poll live
  per-country/tier/club progress instead of a client-side synchronous loop;
  removed the now-unneeded `pool-warm.sh`/`worldpack-warm.sh` cron jobs; split
  World Pack messages onto their own `worldpack` Messenger transport, separate
  from `async` (push notifications) — a country regenerate's 100+ club-generation
  messages were sharing one queue with push sends and delaying each other, so a
  new `docker/worldpack-consume.sh` cron entry drains it independently). Also
  fixed the pre-existing PHPUnit "mock without expectations" notices across the
  suite (`createMock()` → `createStub()` where no `->expects()` is used).

---

- **Commit:** 790e98f (development, merged into dev)
- **Date:** 2026-09-27
- **Stages touched this pass:** 03_data, 04_interfaces (added owner identity
  — `name`/`nationality`/`gender`/`dob`/`appearance` — to `User`, replacing
  the redundant `User::$managerProfile`/`Club::$managerProfile` blobs;
  new `AppearanceRole::OWNER` case wired into `AppearanceLifecycleSubscriber`
  and `app:backfill-appearances`; new `POST /api/owner-avatar` partial-update
  endpoint; `UserCrudController` EDIT enabled with an Owner Identity
  fieldset + the appearance widget; migration `Version20260927120000`)

---

- **Commit:** daea03a (sprites, merged into dev)
- **Date:** 2026-09-26
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui (avatar/kit
  generation demographic + rarity rules: age-banded `hairStyleWeights()`,
  rare ginger `hairColorWeights()`, facial hair extended to players,
  player `face` fixed to neutral, player `headband` at 1-in-1000,
  skin-linked `LipColor::DEEP_BROWN` for dark skin tones, NPC club
  `badgeCentre` generation now excludes `INITIALS` too, and kit
  primary/secondary contrast-checked (WCAG >= 3.0) per variant)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui (restructured
  NpcClub's `identity` to nested `home`/`away` kit variants + shared badge;
  `NpcClub::setIdentity()` now syncs `primaryColor`/`secondaryColor` from
  `identity.home`, replacing `NpcClubGenerationService`'s old separate
  color-pair generator; `primaryColor`/`secondaryColor` no longer
  independently admin-editable; also fixed the shorts/socks/trousers picker
  in both this widget and the player appearance widget to render color chips
  instead of a full kit thumbnail, which couldn't show the selected part
  color at all)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui, 07_synthesis
  (NpcClub kit+badge `identity` config — new `KitIdentityType`/admin widget,
  `NpcClubGenerationService::generateIdentity()`, `LeagueImportExportService`
  export/import coverage; also synced `CLAUDE.md`'s NpcClub entry and added a
  "Kit & Badge Identity" section)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 01_overview (added `output/deployment.md` — the
  three-branch `development`→`dev`/`master` git merge/propagation policy; also
  synced `CLAUDE.md`'s Git Workflow section to match)

---

- **Commit:** 9a5f72f34f7e02b092413285b761463ed08bb91c (as of writing — this pass's own changes are uncommitted on top of it, pending the user's usual explicit commit request)
- **Date:** 2026-09-20
- **Stages touched this pass:** 04_interfaces (new `push-notifications.md`, moved in from `docs/api/push-notifications.md` by explicit request — that file has been deleted; also documents the new `MATCH_RESULT` push type, fired per-fixture on `CompetitionRoundProcessorService::resolveFixture()`, to both sides), 06_documentation (index updated to point at the new location), 07_synthesis (current-focus.md repointed). `01_overview`/`02_architecture`/`05_ui` untouched this pass.
- **Follow-up pass, same session (2026-09-20):** notification observability + admin debug
  tooling, prompted by a real incident (missing `DEV_FIREBASE_SERVICE_ACCOUNT_JSON`/
  `PROD_FIREBASE_SERVICE_ACCOUNT_JSON` GitHub secrets caused push sends to fail silently, with
  zero trace, because `config/packages/messenger.yaml` had no `failure_transport`). Added:
  `NotificationLog` entity/table + `NotificationLogStatus` enum (`03_data/output/entities.md`,
  `schema.md`, `migrations.md` — migration `Version20260920075918`), `NotificationLoggingSubscriber`
  (Messenger `WorkerMessageHandledEvent`/`WorkerMessageFailedEvent` listener — the single place
  that uniformly catches both handler-body and handler-construction failures),
  `NotificationLogCrudController` (read-only), `NotificationDebugController` (force-trigger all 4
  push types at a chosen club, force-process the queue, validate the Firebase connection via new
  `FirebaseConnectionValidator`), and enabled `failure_transport: failed` in
  `messenger.yaml` (reuses the `messenger_messages` table via `queue_name`, no new migration for
  that part). Updated `04_interfaces/output/routes.md`, `controllers.md`, `services.md`. Full test
  suite green (859 tests) as of this pass, including new coverage:
  `NotificationLoggingSubscriberTest`, `NotificationDebugControllerTest`,
  `FirebaseConnectionValidatorTest`.
- **New pass (2026-09-22), on top of commit f770b15a518685a89b788ca1e05c046e3a3dae82 (this pass's own changes are uncommitted on top of it):** decoupled the competition round lifecycle's draw and resolve phases (previously fused into one `CompetitionRoundProcessorService::processRound()` pass) into two independently-scheduled, independently-claimed steps, per an explicit refactor request. `CompetitionRoundStatus` collapsed to `DRAW_PENDING|DRAWN|RESULTS_PUBLISHED|CANCELLED`; `CompetitionRound` gained `matchesResolveAt` and split its single claim column into `drawLockedAt`/`resolveLockedAt` (migration `Version20260922095155`, includes a data-only status-value remap); `CompetitionTemplate` gained `intermissionRatio` (default 0.3). New pure `CompetitionScheduleCalculator` service; `CompetitionRoundProcessorService` deleted and replaced by `CompetitionDrawService` + `CompetitionResultsService`, each behind its own new command/cron entry (`app:competition:draw-rounds`/`app:competition:resolve-rounds`, both every 1 min, replacing `app:competition:process-rounds`). Round 1 no longer draws synchronously at lock time — `CompetitionLockService` now only schedules it (making `ActiveCompetitionStatus::SCHEDULED` a real, observable window for the first time). `CompetitionRoundReminderService` repurposed around the resolve phase (`ROUND_RESOLVING_SOON` on a `DRAWN` round nearing `matchesResolveAt`, not `ROUND_STARTING_SOON` before a draw). Added an admin "Force Draw" action (`CompetitionDrawService::forceDrawRound()`) alongside the existing "Generate Result" one. Updated `03_data/output/{entities,schema,migrations}.md`, `04_interfaces/output/{services,push-notifications}.md`, `02_architecture/output/structure.md` (cron table). Full Competition-area test suite green (273 tests, 2473 assertions) except one pre-existing, unrelated failure (`RewardApplierServiceTest::testApplyingTwiceDoesNotDoubleGrantTheReward` — confirmed failing on the pre-refactor baseline too, not touched by this pass). New test coverage: `CompetitionScheduleCalculatorTest`, `CompetitionLockServiceTest`, `CompetitionDrawServiceTest`, `CompetitionResultsServiceTest`, `CompetitionLifecycleE2ETest`.

---

- **Commit:** dfc8035a8cb9f014abcf5777da6840bc1f18f7ef
- **Date:** 2026-09-18
- **Stages touched this pass:** 01_overview, 02_architecture, 03_data, 04_interfaces, 05_ui, 06_documentation, 07_synthesis (full warm — rebuilt from scratch after retiring the old generator-script-based `.context/`, previous content preserved in this repo's `git stash`)
