# Service-Layer Responsibilities

`src/Service/` — responsibilities drawn from each file's imports/
constructor and, where present, its class docblock — not guessed from
filename alone.

- **`AccountDeletionService`** — permanently deletes a user account and
  every club they own, including cascade-uncovered data, in one
  transaction.
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
  generates a Player/Staff/Scout/Agent's visual appearance fields.
- **`Appearance/SeededRng`** — seeded PRNG kept bit-identical to the
  frontend's `SeededRng` (wunderkind-app) for appearance generation —
  cross-repo invariant, don't change independently.
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
- **`ClubResolver`** — resolves the acting `Club` from the current
  request (explicit id header, falling back to `findByUser()` for legacy
  clients).
- **`CommunityStatsService`** — computes community-wide stats (most
  transfers/development/seasons/trophies) from match/transfer/season
  repositories.
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
  config (formations, reputation tiers, trophy colours, city sizes).
- **`LeagueService`** — league lifecycle logic (season conclusion,
  leaderboard categories), using `LeagueRepository`,
  `GameConfigRepository`.
- **`LiveTelemetryService`** — feeds the landing page's live activity
  feed; some entries (sackings, contract disputes, youth intake) are
  illustrative and have no backing data — don't treat as real metrics.
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
  (events, playing styles, facility/tactical/archetype templates).
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
  `FacilityTemplateRepository`, `GameConfigRepository`, `LeagueService`.
- **`PeriodResolver`** — resolves stats time-period filters
  (`StatsPeriod`) into query constraints against `SeasonRecord`.
- **`Personality/PersonalityContext`** — builds role-specific
  personality-generation context (independent of skill level).
- **`Personality/PersonalityGeneratorService`** — generates a
  Staff/Scout personality matrix (Gaussian-based, `gaussianInt()`).
- **`PlayerGenerationService`** — generates a `Player` blueprint
  (position, recruitment source, personality matrix feeding into
  attribute derivation).
- **`SocialPostRenderer`** — renders `SocialPostTemplate` content with
  stat-category/period substitutions.
- **`SocialPostingService`** — publishes to connected social platforms
  via `HttpClientInterface`, raising `SocialPostingException` on failure.
- **`StarterPackService`** — assembles/grants a new club's starter pack
  (players/staff/scouts) via `ClubInitializationService`.
- **`SyncService`** — processes client sync payloads
  (`SyncService::process()`); applies an **absolute** `Club::setBalance()`
  call, not an accumulate — a client resync overwrites, doesn't add.
- **`TokenEncryptionService`** — encrypts/decrypts OAuth tokens using
  libsodium secretbox (key generated via
  `sodium_crypto_secretbox_keygen()`).
- **`TransferLeaderboardService`** — computes top-sellers/most-valuable
  transfer leaderboards from `TransferRepository`.
- **`WorldInitializationService`** — initializes a country's world data
  (players/staff/scouts pools, starter configs) on first access.
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

No `src/EventListener/` directory exists.
