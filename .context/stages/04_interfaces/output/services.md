# Service-Layer Responsibilities

`src/Service/` — responsibilities drawn from each file's imports/
constructor and, where present, its class docblock — not guessed from
filename alone.

- **`AccountDeletionService`** — permanently deletes a user account and
  every club they own, including cascade-uncovered data, in one
  transaction. `BLOCKING_CLUB_DEPENDENTS` is the hand-maintained list of
  club dependents whose FK is NO-ACTION at the DB level and so must be
  deleted before their club; **a new entity with a non-nullable `club` FK
  must be added here and to `DeleteAdminController::cleanClubForeignKeys()`
  (the admin's own delete path), or both deletes raise
  `ForeignKeyConstraintViolationException`** — as `UserLedger` did in
  production. `AccountDeletionServiceTest` builds one row per dependent, so
  the regression is only caught for entities actually present in that
  fixture.
- **`Admin/DashboardStatsService`** — computes admin dashboard growth/
  leaderboard/pool stats; Postgres-dialect-specific SQL confined here.
- **`Admin/StatBuckets`** — bucketing helper backing dashboard facet
  charts.
- **`AdminMessageService`** — resolves which announcements a club should
  see and records that it has seen them. `isEligible()` is public
  specifically so `ResolveAdminMessageAudienceForPushMessageHandler` can
  reuse the exact same group-targeting rules for push's eager
  (all-clubs-at-once) resolution instead of this poll path's per-request
  lazy check — the two must never diverge.
- **`Appearance/AppearanceGeneratorService`** — deterministically
  generates a Player/Staff/Scout/Agent's 15-key sprite `appearance` config
  (id/role/age/nationality-seeded). As of the pixel-sprite rewrite there is
  **no frontend generator to stay bit-identical with** for this shape — only
  rendering code (`sprite.ts`/`PlayerSprite.tsx`) exists in `wunderkind-app`
  — so the draw order is a backend-only design, not a cross-repo invariant.
- **`Appearance/SeededRng`** — seeded LCG PRNG used by the generator above.
  No longer load-bearing as a cross-repo bit-parity contract (see above);
  kept as the generator's RNG utility.
- **`ArchetypeResolverService`** — read-only preview of archetype
  matching against `PlayerArchetype::$traitWeights`.
- **`ArchetypeShowcaseService`** — picks a best-match positive/negative
  archetype pair per player for marketing display.
- **`AudienceCriteriaEvaluator`** — evaluates admin-authored JSON
  audience-targeting criteria, failing closed (under-deliver rather than
  broadcast) on malformed input.
- **`ClubInitializationService`** — creates/initializes a `Club` for a
  `User` (name uniqueness via `ClubNameTakenException`, country/
  starter-config wiring).
- **`ClubNameNormalizer`** — normalizes club names; mirrored exactly by
  the client's `clubName.ts` — cross-repo invariant.
- **`ClubResolver`** — resolves *which* club a request is about, for
  accounts that own more than one (one club per local save/game). Live
  requests (`InboxController`, `CompetitionController`, `ClubController`,
  `FinanceController`, `LeagueController`, `MarketController`,
  `AdminMessageController`, `InitializeController`) call
  `resolveFromRequest()`, which reads the **`X-Club-Id` header** — the
  client sets it from whichever save is currently loaded. Deferred/queued
  payloads that are built on-device and may be delivered long after the
  save has switched (`SyncRequest`, `ConcludeSeasonRequest`) instead carry
  a `clubId` field in the request **body**, resolved via `resolve($user,
  $clubId)`. Either path throws `ClubMismatchException` (→ 4xx) if the id
  names a club the caller doesn't own. Omitting the id — the only
  behavior pre-dating this mechanism — falls back to
  `ClubRepository::findByUser()`, i.e. a guess at "the newest club",
  kept only so older, unpatched clients keep working. Not documented in
  `docs/api/` as of 2026-09-24; this entry is the source of truth for the
  header name/contract until a proper API doc exists.
- **`CommunityStatsService`** — computes community-wide stats (most
  transfers/wins/development/seasons/trophies) from match/transfer/season
  repositories. Every ranked row is decorated with the club's
  `country`/`homeKitConfig`/`awayKitConfig`/`badgeConfig` via one
  `ClubRepository::findByIdsKeyed()` lookup over the already-ranked ids — the
  same identity block `LeaderboardItemDto` emits, so one client-side renderer
  serves both boards.
  `getMostDevelopment()` ranks `Transfer::$developmentPoints`, **a field nothing
  writes** (`TransferSyncDto` has no such member; the only writer is
  `app:seed-community-stats-fixtures`), so it returns an all-zero column in
  production. Kept as a published endpoint; the landing page shows
  `getMostWins()` in its place.
- **`Competition/BracketLabeler`** — generates stable round labels used
  as keys into `CompetitionTemplate::roundEngineConfig` and stored on
  `CompetitionRound::label`.
- **`Competition/CompetitionScheduleCalculator`** — pure, stateless
  interval math (no DB): splits a round's time-budget
  (`totalDurationSeconds / roundCount`, via `intervalSeconds()`) into a
  pre-resolve "reveal" window and a post-results "intermission" window
  (`splitInterval()`, ratio from `CompetitionTemplate::intermissionRatio`,
  floored at 120s per half — under that, the split is skipped entirely
  and the whole budget goes to reveal), plus `firstRoundLeadTimeSeconds()`
  (round 1's own pre-draw lead time, reusing the same ratio against round
  1's own budget rather than a second config value). Shared by
  `CompetitionLockService`, `CompetitionDrawService` and
  `CompetitionResultsService` below.
- **`Competition/CompetitionLockService`** — locks a competition instance
  the instant the last slot fills: computes the full round schedule and
  persists every `CompetitionRound` row as `DRAW_PENDING` (round 1's
  `scheduledAt` = now + `CompetitionScheduleCalculator`'s lead time; later
  rounds get a non-binding placeholder, overwritten before ever queried).
  No fixtures are seeded here and no push is sent — drawing (including
  round 1's) is exclusively `CompetitionDrawService`'s job now, symmetric
  across every round.
- **`Competition/CompetitionRegistrationService`** — registers entrants,
  using `SELECT ... FOR UPDATE` on the `ActiveCompetition` row to
  serialize concurrent last-slot registrations.
- **`Competition/CompetitionDrawService`** — owns the draw phase
  (`DRAW_PENDING` → `DRAWN`) behind `app:competition:draw-rounds` (every 1
  min via cron — see `02_architecture/output/structure.md`). Atomically
  claims + draws every due round (round 1: pairs entrants by seed; round
  N>1: pairs the previous round's winners), sets `startedAt` +
  `matchesResolveAt` (via `CompetitionScheduleCalculator`), flips
  `ActiveCompetition` `SCHEDULED`→`RUNNING` on round 1, and sends a
  `PushNotificationService` `ROUND_DRAWN` push to every entrant placed
  into the round. `forceDrawRound()` is an admin-only bypass of a round's
  `scheduledAt` wait (mirrors `CompetitionResultsService::
  forceResolveFixture()` below).
- **`Competition/CompetitionResultsService`** — owns the resolve +
  finalization phase (`DRAWN` → `RESULTS_PUBLISHED`) behind
  `app:competition:resolve-rounds` (every 1 min). Atomically claims (via
  `resolveLockedAt`, which deliberately does **not** flip `status` away
  from `DRAWN` — a round can sit "claimed, partially resolved" across
  multiple `forceResolveFixture()` admin calls) + resolves every due
  round's fixtures, sending a personalized `MATCH_RESULT` push per side
  (winner "Victory!" / loser "Eliminated", same `data` payload either
  way) for every fixture; for a non-final round, writes the **next**
  round's `scheduledAt` (`completedAt + intermissionSeconds`) without
  drawing it — that's a later `CompetitionDrawService` tick; for the
  final round, crowns the winner(s), applies the prize via
  `RewardApplierService`, completes the `ActiveCompetition`, and sends a
  `COMPETITION_COMPLETED` push to the champion(s) (`result: "WON"`) and
  the final's loser(s) (`result: "RUNNER_UP"`) — see `finalizeRound()`.
  `forceResolveFixture()` is an admin-only bypass of a fixture's round's
  `matchesResolveAt` wait, sharing the same claim/resolve/finalize
  machinery as the scheduled path. (Replaces the old fused
  `CompetitionRoundProcessorService`, which drew a round's next fixtures
  and resolved its own results in the same pass — see
  `03_data/output/migrations.md`'s most recent entry.)
- **`Competition/CompetitionRoundReminderService`** — sends a
  `ROUND_RESOLVING_SOON` push to a `DRAWN` round's actual participants 15
  minutes before its `matchesResolveAt` (a `DRAW_PENDING` round has no
  fixtures yet to remind anyone about), via the
  `app:competition:send-round-reminders` command (every 5 min). Same
  atomic-UPDATE claim-lock idiom as `CompetitionDrawService::claimDraw()`/
  `CompetitionResultsService::claimResults()` (a `reminderSentAt` column
  on `CompetitionRound`), kept as a fully separate service so a reminder
  bug can't threaten bracket-processing correctness.
- **`Competition/CompetitionAutoFillService`** — dev/testing convenience:
  once an instance has at least one entrant and its template opted in
  (`CompetitionTemplate::$autoFillSpoofEntrants`), fills every remaining
  slot with spoof entrants a configurable delay (5/10/20/30/60 min,
  `$autoFillDelayMinutes`) after that first entrant's `registeredAt`, via
  the `app:competition:auto-fill-spoof-entrants` command (every 1 min —
  the finest delay is 5 min). Delegates the actual fill to
  `CompetitionSpoofEntrantService::spoofAllEntrants()`, so a filled
  instance auto-locks exactly like a genuinely full house (round 1 then
  draws on `CompetitionDrawService`'s own schedule, same as any other
  round — no longer synchronous with locking).
  No separate "already filled" flag — an instance that reaches capacity
  stops being `REGISTERING` and so naturally drops out of eligibility.
- **`Competition/CompetitionSpoofEntrantService`** — admin-only override
  that calls `CompetitionRegistrationService::register()` directly,
  bypassing HTTP/JWT/eligibility checks. `spoofAllEntrants()` is also the
  engine behind `CompetitionAutoFillService` above.
- **`Competition/EligibilityEvaluator`** — evaluates every competition
  eligibility rule with no short-circuit, so all blocking reasons are
  reported at once. Also drives `NEW_COMPETITION_OPEN`'s audience filter
  (`ResolveNewCompetitionAudienceForPushMessageHandler`) — only clubs that
  would actually be allowed to register for a newly-opened instance are
  notified.
- **`Competition/EligibilityResult`** — value object carrying every
  blocking reason for register/available endpoints.
- **`Competition/RewardApplierService`** — applies competition rewards to
  entrants/clubs.
- **`Competition/SnapshotValidator`** — validates match-result snapshot
  payload shapes.
- **`ConfigImportExportService`** — imports/exports admin game-config
  JSON (contract enforced by `ConfigImportExportCoverageTest`).
- **`EconomicService`** — club/investor/sponsor economic logic (tiers,
  statuses), using `GameConfigRepository`, `InvestorRepository`,
  `SponsorRepository`.
- **`EmailVerificationService`** — issues/validates email verification
  codes; sends verification/reset emails via `MailerInterface`.
- **`FacilityImageResolver`** — resolves facility image assets for
  `GameConfigController`.
- **`FixtureGenerationService`** — generates competition/league
  fixtures.
- **`HallOfFameScoreService`** — computes hall-of-fame scores; a
  previously-fixed bug here involved an unsent high-water-mark value
  pinning leaderboard reads at 0 — worth checking if hall-of-fame numbers
  ever look stuck again.
- **`InboxService`** — builds/manages a club's inbox messages
  (investor/sponsor status-driven).
- **`LeaderboardCalculationService`** — computes and caches (via
  `TagAwareCacheInterface`, `app.leaderboard_cache` pool) leaderboard
  entries from career stats/transfers.
- **`LeagueImportExportService`** — imports/exports league/NPC-club
  config (formations, reputation tiers, trophy colours, city sizes,
  kit+badge `identity`).
- **`LeagueService`** — league lifecycle logic (season conclusion,
  leaderboard categories), using `LeagueRepository`,
  `GameConfigRepository`. `generateLeaguesForCountry()` reads its per-tier
  financial/trophy/sponsor defaults from `GameConfig::getLeagueTierDefaultsForTier()`
  (DB-backed, shared across every country) rather than a hardcoded const —
  editable via the admin "League Tier Defaults" bulk-edit screen
  (`admin_leagues_overview` → `LeagueAdminController::bulkEditTierDefaults()`,
  route `admin_league_tier_defaults_bulk_edit`), which can also cascade the
  submitted values onto selected already-existing `League` rows.
- **`App\Service\CountryContent\CountryContentRegistry`** — single canonical
  per-country content store (place/population/region/capital data, club-name
  suffixes, stadium formats, `WorldRegion`, `MarketPoolService` business
  cluster), keyed by `App\Enum\Country`. Replaced four previously-scattered,
  independently-drifting consts. See
  `docs/world-generation/adding-a-country.md`.
  Its `WORLD_REGIONS` table is the **only** demonym→skin-tone route: every
  nationality resolves `Country::fromNationality()` → `worldRegion()` →
  `WorldRegion::skinWeights()`, and anything unresolved falls back to a
  *uniform* roll across all six `SkinId`s rather than a default region. A
  `Country` with no entry here therefore silently loses its skin weighting, and
  a `WorldRegion` with no country pointing at it can never be selected at all —
  `EASTERN_EUROPE` sat orphaned in exactly that state until `PL` was added.
  `WorldRegionTest` now asserts both directions.
- **`OwnerSpotlightService`** — the landing page's "Owner Spotlight" (richest
  owners + their clubs and divisions, under Chapter 01 of the Journey section).
  Deliberately **not** modelled like `ClubSpotlight`/`LiveTelemetrySnapshot`: it
  is two indexed queries over club/user, so it is computed live behind the same
  short-lived cache `WorldOverviewService` uses — no entity, migration or cron
  entry. Ranks on **`UserLedgerRepository::findTopOwnersByBalance()`** — each owner's
  latest `balanceAfterPence`, i.e. money actually drawn — not on summed club
  balance and not on the deprecated `Club::$totalCareerEarnings`. Owners with no
  ledger entries are absent rather than listed at zero, so the panel is sparse
  (production held **one** ledger entry across 162 owners at the time of the
  switch) and fills in as clients report `dividend_draw`. See
  `docs/api/user-ledger.md`.
- **`ReputationDecayService`** — pure arithmetic for dormancy-based reputation
  decay (5 points per whole day a club doesn't sync); `app:reputation:decay`
  owns iteration and persistence. Decay is **transient by design** — reputation
  is client-authoritative and the next sync restores it. Full rationale,
  including why there is no separate backfill command and why the command
  writes through to the non-aggregate `club_reputation` leaderboard entries
  itself, is in `docs/reputation-decay.md`.
- **`LiveTelemetryService`** — feeds the landing page's live activity
  feed; some entries (sackings, contract disputes, youth intake) are
  illustrative and have no backing data — don't treat as real metrics.
  `aggregate()` also produces `totalGoals`/`totalMatches` for the HUD's
  "Goals Logged" box, summed from `fixtures[]` and **deduplicated by
  `fixtureId`** — that array carries the syncing club's WHOLE division's
  round (see `attachClubDossiers()`), so every player-owned club in a
  division reports the same fixtures and a club syncing twice resends them.
  Measured on real data: 860 raw fixture rows reduce to 627 distinct ones,
  so skipping dedup would have reported 2,586 goals instead of 1,868. The
  `wins/draws/losses` tally above still knowingly double-counts because
  `form[]` carries no fixture identity to dedupe on.
  Any figure derived from `payload.ledger[].amount` (`capitalDeployedPence`,
  `buildLedgerEvents()`'s spend lines) goes through the class's
  `ledgerAmountToPence()` helper to correct for that field's 100x-true-pence
  convention — see `03_data/output/schema.md`'s "`sync_record.payload` field
  conventions" section. `promises[].offer.amountPence` needs no such
  correction.
  Each selected feed item also carries a `clubId` and a per-club `dossier`
  (latest `MatchResult`s + best-rated player from `playerStats[]`), attached by
  `attachClubDossiers()` after selection — the only non-static step in the feed
  pipeline, since it needs MatchResult rows no payload carries. Keyed on clubId,
  never clubName (names are not unique). The dossier feeds the boardroom overlay
  (`_incident_modal.html.twig`). `findBestRatedPlayer()` recovers `position` from
  `fixtures[].{home,away}PlayerRatings[]` (playerStats carries none) and merges the
  club's home kit into the client-reported personal-traits `appearanceConfig`; when
  no appearanceConfig was reported it returns null rather than inventing a likeness.
- **`MarketDataService`** — assembles market listing data (agents/
  investors/scouts/sponsors/staff) into `MarketDataResponse`.
- **`MarketPoolService`** — manages the shared unassigned-entity pool
  (players/staff/scouts/sponsors) and top-up/consume operations.
- **`MatchEngine/AiEngineStub`** — stub match engine standing in for real
  LLM-based match resolution (explicitly out of Phase 1 scope per its own
  code).
- **`MatchEngine/DeterministicEngine`** — full port of `wunderkind-app`'s
  `ResultsEngine.ts`: dominance score (ability/morale/condition, tactics,
  manager, personality, cohesion) drives Poisson-distributed goals,
  then position-weighted goals/assists/cards/ratings across the XI. A
  Competition fixture can never end level — a 90' draw triggers a real
  extra-time simulation, and only falls to a coin-flip penalty shootout
  (fabricated but plausible scoreline) if still level after that. Hands
  off to `MatchNarrativeGeneratorService` for `narrativePayload`.
- **`MatchEngine/MatchNarrativeGeneratorService`** — walks the
  `EventCategory::MATCH_NARRATIVE` chain-graph (ported from
  `wunderkind-app`'s `narrativeEngine.ts`/`matchTimelineGenerator.ts`)
  to produce the full ordered commentary timeline server-side, including
  extra-time/penalty-shootout markers when applicable.
- **`MatchEngine/MatchEngineInterface`** — contract for match engines
  (input: `CompetitionEntrant`/`CompetitionFixture`).
- **`MatchEngine/MatchEngineRegistry`** — tagged-iterator dispatcher
  selecting a `MatchEngineInterface` by `MatchEngineIdentifier` (see
  `config/services.yaml`'s `_instanceof` binding).
- **`MatchEngine/MatchEngineResult`** — plain value object for a match
  result (score, eventLog, lineups, narrativePayload,
  wentToExtraTime/wentToPenalties/penalty scores), persisted by the
  caller into `CompetitionResult`.
- **`NameGeneratorService`** — generates names (players/staff/clubs).
- **`NarrativeImportExportService`** — imports/exports narrative content
  (events, playing styles, facility/tactical/archetype templates,
  excursions), plus a `translations` section (non-default-language values
  only, for GameEventTemplate/FacilityTemplate/Excursion/PlayerArchetype
  text fields) — see `NarrativeTranslationService` below and CLAUDE.md's
  "Languages & Translations". `EXPORT_VERSION` bumped to `3` when
  `translations` was added, then `4` when `excursions` (base content, not
  just its translations) joined. `GameEventTemplate`/`PlayerArchetype`
  still export via hand-written field lists (verified complete, but
  guarded against future drift only by `NarrativeFieldCoverageTest`, not
  a `toArray()` method); `Excursion` now exports via `toArray()`, same
  self-verifying convention as `FacilityTemplate`.
- **`NarrativeTranslationService`** — find-or-create for `TranslationKey`s
  linked to one narrative entity field (`ensureKeyFor()`); builds the
  per-(type, language) localization map the `?lang=`-aware endpoints
  (`EventController`, `ExcursionController`, `GameConfigController`,
  `ArchetypeController`) read (`buildLocalizationMap()`); saves the admin
  "Translations" quick-edit screen's submission (`saveTranslations()`).
  The default (EN) value for a narrative field is never stored — always
  read live off the entity via `getFieldValue()`. Deliberately has no
  instance-level cache in `ensureKeyFor()` (see its docblock) —
  `saveTranslations()` dedupes locally instead, since it's the one caller
  that can legitimately ask for the same not-yet-existing key twice (two
  languages, one call). Covers `GameEventTemplate`/`FacilityTemplate`/
  `Excursion`/`PlayerArchetype`.
- **`TranslationCatalogueService`** — builds the generic UI-copy
  `{_meta, entries}` catalogue (`GET /api/translations/{code}` and the
  admin bulk-export), reading `TranslationRepository::
  getGenericCatalogueForLanguage()` in one query rather than per-key.
- **`TranslationCatalogueImportExportService`** — bulk import/export of
  the generic UI-copy catalogue, one language at a time
  (`/admin/translations/content|export|import`). A separate domain from
  `NarrativeImportExportService`'s own `translations` section. Rejects an
  `entries` key that belongs to a narrative-linked `TranslationKey` rather
  than silently overwriting it. `import()`'s optional `$clearFirst` flag
  is scoped to **one language's generic values only** (never a full wipe,
  unlike the narrative import's own "clear existing" checkbox) — a bulk
  import always carries just one language's file, so clearing every
  language would destroy unrelated languages' work as collateral damage.
  `TranslationRepository::deleteGenericForLanguage()` deletes that
  language's rows first; `TranslationKeyRepository::findGenericOrphaned()`
  then prunes any key left with zero translations in *any* language (a
  key still holding a value elsewhere is never touched).
- **`Notification/PushNotificationService`** — the only thing a call site
  should touch to send a push notification:
  `notifyUsers(userIds, title, body, data)` dispatches
  `SendPushNotificationMessage` via Symfony Messenger (async, first use
  of Messenger in this codebase — see `02_architecture/output/
  structure.md`). No call site talks to Messenger or Firebase directly.
- **`Notification/FirebaseMessagingFactory`** — builds the Kreait
  `Messaging` service directly from `FIREBASE_SERVICE_ACCOUNT_JSON`
  (`json_decode` + `Factory::withServiceAccount()`), bypassing
  `kreait/firebase-bundle`'s own YAML credential binding, which has been
  unreliable for a multi-line JSON string with an embedded PEM private
  key (same class of trap as `JWT_SECRET_KEY`, see
  `docs/deploy/hetzner.md`). Wired as a DI factory in
  `config/services.yaml`, not autowired via the bundle's own config.
- **`MessageHandler/SendPushNotificationMessageHandler`** — the actual
  FCM send: resolves `UserDevice` rows fresh at handle time (not
  pre-resolved at dispatch), multicasts in chunks of ≤500 tokens (FCM's
  own limit), and deletes any `UserDevice` FCM reports as
  unknown/invalid.
- **`EventSubscriber/NotificationLoggingSubscriber`** — listens to
  Messenger's `WorkerMessageHandledEvent`/`WorkerMessageFailedEvent`
  (filtered to `SendPushNotificationMessage` and any
  `App\Message\AudienceResolutionMessage` — currently
  `ResolveAdminMessageAudienceForPushMessage` and
  `ResolveNewCompetitionAudienceForPushMessage`), persisting one
  `NotificationLog` row per message actually processed. Deliberately the
  *only* place this is logged — not the handlers — since this event pair
  is the one place that uniformly catches both a handler's own thrown
  exception and a handler-*construction* failure (e.g.
  `FirebaseMessagingFactory::create()` throwing because
  `FIREBASE_SERVICE_ACCOUNT_JSON` is missing/malformed — a real incident
  this subscriber exists to make visible; previously such a failure
  discarded the message with zero trace, since
  `config/packages/messenger.yaml` had no `failure_transport`). For an
  `AudienceResolutionMessage`, the handler's return value (the resolved
  recipient count) is read back via Messenger's `HandledStamp` and folded
  into the log summary — another real incident (an admin broadcast logged
  "success" with 0 actual recipients, indistinguishable from a genuine
  send, because 0 registered devices existed) this exists to make visible.
- **`Notification/FirebaseConnectionValidator`** — "is Firebase actually
  configured correctly right now," for the admin debug page
  (`NotificationDebugController`). Two tiers: (1) structural — does
  `FirebaseMessagingFactory::create()` succeed, no network call; (2)
  live — a `validateOnly: true` send to a dummy token, triaging kreait's
  `Messaging` exception classes to tell "credentials are bad" apart from
  "credentials are fine, the dummy token was correctly rejected."
- **`MessageHandler/ResolveAdminMessageAudienceForPushMessageHandler`**
  — eager, one-time audience resolution for an `AdminMessage`'s push
  channel (`AdminMessage::$sendAsPush`) — reuses
  `AdminMessageService::isEligible()` across every club with a
  registered device, then dispatches chunked
  `SendPushNotificationMessage`s. Only fires once per message
  (`AdminMessage::$pushSentAt` guards re-sends), dispatched from
  `AdminMessageCrudController::persistEntity()`/`updateEntity()`.
  Implements `App\Message\AudienceResolutionMessage` (shared with
  `ResolveNewCompetitionAudienceForPushMessage` below) so
  `NotificationLoggingSubscriber` can log both uniformly.
- **`MessageHandler/ResolveNewCompetitionAudienceForPushMessageHandler`**
  — the `NEW_COMPETITION_OPEN` counterpart, dispatched from
  `app:competition:provision-instances` only when it actually creates a
  new instance (not on every tick that finds one still open). Same
  broadcast-to-every-device-holding-club shape as the AdminMessage
  handler, but filtered by `EligibilityEvaluator::evaluate()` (the
  template's own fixed entry constraints) instead of an arbitrary
  `AudienceGroup` criteria bag.
- **`NpcClubGenerationService`** — generates NPC clubs using
  `FacilityTemplateRepository`, `GameConfigRepository`, `LeagueService`;
  each club also gets a `generateIdentity()`-produced home+away kit+badge
  config (`randomKitVariant()` run twice). The home kit's colors become the
  club's `primaryColor`/`secondaryColor` via `NpcClub::setIdentity()`'s
  auto-sync — there's no separate color-pair generator any more (see
  CLAUDE.md's "Kit & Badge Identity"). Its place-name/suffix/stadium-format
  content now comes from `CountryContentRegistry` (keyed by `Country`), not
  its own consts — see that service above and
  `docs/world-generation/adding-a-country.md`.
- **`PeriodResolver`** — resolves stats time-period filters
  (`StatsPeriod`) into query constraints against `SeasonRecord`.
- **`Personality/PersonalityContext`** — builds role-specific
  personality-generation context (independent of skill level).
- **`Personality/PersonalityGeneratorService`** — generates a
  Staff/Scout personality matrix (Gaussian-based, `gaussianInt()`).
- **`PlayerGenerationService`** — generates a `Player` blueprint
  (position, recruitment source, personality matrix feeding into
  attribute derivation). `generate()` takes two optional overrides of
  `PoolConfig`'s defaults, same shape: `$potentialRange` (world-pack tier
  bands) and `$ageRange` (first-team squads, so an NPC side is never built
  out of the pool's youth age band — see `PoolConfig::firstTeamAgeRange()`).
  Both null by default, so existing callers are unaffected; an inverted range
  clamps to its floor rather than reaching `random_int()` as min > max.
- **`PlayerSnapshotSerializer`** — **THE canonical player shape for every
  device-facing response.** See "One player shape" below; start here before
  adding any endpoint that returns a player.
- **`PlayerBrowseSerializer`** — now a thin `withGuardians: true` preset over
  `PlayerSnapshotSerializer`, kept as its own class because "browsing includes
  guardians, world packs deliberately don't" is a real decision and both
  controllers type-hint it. It no longer owns a shape of its own; the two
  formerly-divergent shapes were consolidated.
- **`YouthPoolService`** — the stratified youth draw behind
  `GET /api/players/youth`. **Draws from the existing pool, never generates**,
  so the tier/nationality spreads are properties of what has been seeded, not
  knobs it can turn: it asks for a target per (tier × nationality) cell, backfills
  the shortfall from the rest of the age window, and returns the *realised*
  distribution so an under-seeded pool reads as an empty shelf rather than a bug.
  Bands on `potential`, not `currentAbility` — a 12–14 year old's ability is
  `abilityTarget × potential` with `abilityTarget` only 0.30–0.60 under 21, so
  ability banding collapses a youth pool into one tier (measured: `currentAbility`
  never exceeded 69 across a real 5,412-player pool, so nothing was ever `elite`).
  Age is a `date_of_birth` window (`dobWindow()`), never a second age formula, and
  is served by `idx_player_youth_draw`. Non-consuming by design. The per-tier
  home-nationality mix is read from `PoolConfig` rather than a const, so it is
  admin-tunable; `TIER_WEIGHTS` stays a const deliberately.
- **`SocialPostRenderer`** — renders `SocialPostTemplate` content with
  stat-category/period substitutions.
- **`SocialPostingService`** — publishes to connected social platforms
  via `HttpClientInterface`, raising `SocialPostingException` on failure.
- **`StarterPackService`** — assembles/grants a new club's starter pack
  (players/staff/scouts) via `ClubInitializationService`. **Player draws are
  gated on a `date_of_birth` cutoff** derived from
  `PoolConfig::$playerFirstTeamAgeMin`: the pool deliberately spans youth ages
  to supply `GET /api/players/youth`, so a draw filtered only on ability,
  position and nationality handed new clubs 11-12 year olds. An under-age pool
  now under-fills loudly (`starter_pack.initialize.position_underfilled`) and,
  if nothing at all qualifies, raises `InsufficientStarterPoolException` —
  a visible failure rather than a squad of children.
- **`SessionRevocationService`** — signs a `User` out everywhere. Owns **both**
  halves, because either alone leaves them partially logged in: it bumps
  `User::$tokenVersion` (retiring every already-issued stateless access token —
  see `03_data/output/entities.md`) *and* deletes the account's rows from
  `refresh_tokens`, which are real stored credentials (30d TTL,
  `single_use: false`) that would otherwise mint a fresh access token seconds
  later. Rows are matched on `username`, i.e. `getUserIdentifier()`/email, not
  the id. Also exposes `countActiveSessions()` (unexpired refresh tokens only —
  access tokens are stateless and uncountable, so it is a floor, not a total).
- **`SyncService`** — processes client sync payloads
  (`SyncService::process()`); applies an **absolute** `Club::setBalance()`
  call, not an accumulate — a client resync overwrites, doesn't add.
- **`TokenEncryptionService`** — encrypts/decrypts OAuth tokens using
  libsodium secretbox (key generated via
  `sodium_crypto_secretbox_keygen()`).
- **`TransferLeaderboardService`** — computes top-sellers/most-valuable
  transfer leaderboards from `TransferRepository`.
- **`UserLedgerService`** — single writer of `UserLedger` rows (currently just
  dividend draws). `recordDividendDraws()` scans a sync payload's free-form
  `ledger[]` array for `category === 'dividend_draw'` entries and persists one
  `UserLedger` row per match, chaining the user's overall centralized
  balance (`balanceBeforePence`/`balanceAfterPence` on each row) across
  entries. Called from `SyncService::process()` on every live sync, and from
  the one-time `app:backfill-user-ledger` command replaying every historical
  `SyncRecord` — both share this one implementation so the before/after
  chaining logic exists in exactly one place. Idempotent per
  `(sourceSyncRecord, sourceLedgerIndex)` via
  `UserLedgerRepository::existsForSource()`. `dividend_draw`'s `amount` is
  taken as real pence, unlike every other `ledger[].category` (which is
  100x-inflated) — a deliberate choice for this brand-new category, not an
  oversight; see `schema.md`'s `sync_record.payload` field conventions and
  `docs/api/user-ledger.md`. See "Centralized User Ledger" in CLAUDE.md.
- **`WorldInitializationService`** — initializes a country's world data
  (players/staff/scouts pools, starter configs) on first access.
  `buildLeaguesPack()`/`buildTierPack()` draw each NPC club's full staff —
  Manager/Coach/Chairman (unfiltered draw) plus Director of Football/Facility
  Manager/Scouts (nationality-filtered with backfill, via `fillStaffRole()`/
  `fillScouts()`) — sized per league tier by
  `StarterConfig::$npcSquadConfig`. Staff (all 5 roles) are pool-deleted on
  consumption like players; Scouts are not (shared pool, like Agent) and are
  surfaced in a new `scouts[]` array on each NPC club snapshot
  (`buildClubSnapshot()`). See `docs/api/npc-club-staff-scouts.md`.
- **`WorldOverviewService`** — builds world overview figures, consumed
  both by `GET /api/world/overview` and directly by `LandingController`
  for server-rendering.
- **`WorldPackCacheService`** — caches per-country/tier "world pack" data
  (`CountryWorldPackCache`).
- **`YouTubeFeedService`** — fetches/filters the YouTube channel feed
  (drops entries with an empty `<media:title>`).

## Event subscribers (`src/EventSubscriber/`)

Not a service-layer concern per se, but worth noting alongside services
since they run implicitly on every entity write:

- **`AppearanceLifecycleSubscriber`** (`prePersist`) — auto-fills a
  generated appearance on any `Player`/`Staff`/`Scout`/`Agent` persisted
  without one, via `AppearanceGeneratorService` — covers every creation
  path (services, commands, admin).
- **`PersonalityLifecycleSubscriber`** (`prePersist`) — auto-fills the
  Personality Matrix on any `Staff`/`Scout` persisted without one, via
  `PersonalityGeneratorService`. `Player` is deliberately excluded —
  `PlayerGenerationService` rolls its own matrix.

## One player shape

**The world-pack player snapshot is the universally expected structure whenever
the API returns a player object to the frontend device.** Any new endpoint that
returns a player emits this shape — do not invent a per-endpoint variant, and do
not reach for a different serializer.

It is produced by `PlayerSnapshotSerializer`. `WorldPackSnapshotBuilder::
buildPlayerSnapshot()` and `PlayerBrowseSerializer` both delegate to it, so the
world pack, starter pack, `/api/scout/search`, `/api/players/foreign` and
`/api/players/youth` all serve byte-identical keys and the client needs exactly
one upsert path.

There used to be two shapes that overlapped on ~20 keys and disagreed on five.
Consolidation was a strict **superset** — no key either shape emitted was removed
or redefined:

- browse endpoints **gained** `morale`, `isActive`, `potentialTier`
- the world-pack snapshot **gained** `tier`, `overall`, `potentialTier`

Two deliberate exceptions to "one shape", both opt-in flags on the serializer:

- **`guardians`** — on for browse and youth, **off for world packs**.
  `Player::$guardians` is a lazy collection, so including it in a country-sized
  pack would fire a query per player across thousands of players.
- **`isYouth`** — set only by `/api/players/youth`, and *omitted* rather than
  emitted as `false` elsewhere, so no pre-existing response gained a key.

`tier` vs `potentialTier`: `tier` keeps its original `currentAbility` meaning so
already-shipped clients are unaffected; `potentialTier` bands `potential` and is
the one to classify youth on. `PlayerSnapshotShapeConsolidationTest` pins both
legacy key sets literally — those arrays are a historical record of what shipped,
not a description of current output, and must never be edited to match new output.

No `src/EventListener/` directory exists.
