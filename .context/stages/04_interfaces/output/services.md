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
- **`Competition/CompetitionLockService`** — locks a competition instance
  and schedules its first round the instant the last slot fills; also
  triggers a `PushNotificationService` "round drawn" push to every
  entrant placed into round 1.
- **`Competition/CompetitionRegistrationService`** — registers entrants,
  using `SELECT ... FOR UPDATE` on the `ActiveCompetition` row to
  serialize concurrent last-slot registrations.
- **`Competition/CompetitionRoundProcessorService`** — core scheduling/
  idempotency/bracket-advancement logic behind the
  `app:competition:process-rounds` command (runs every 1 minute via cron
  — see `02_architecture/output/structure.md`). Sends a
  `PushNotificationService` "next round drawn" push to advancing winners
  each time a round completes and the next round's fixtures are seeded.
- **`Competition/CompetitionSpoofEntrantService`** — admin-only override
  that calls `CompetitionRegistrationService::register()` directly,
  bypassing HTTP/JWT/eligibility checks.
- **`Competition/EligibilityEvaluator`** — evaluates every competition
  eligibility rule with no short-circuit, so all blocking reasons are
  reported at once.
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
  (filtered to `SendPushNotificationMessage`/
  `ResolveAdminMessageAudienceForPushMessage`), persisting one
  `NotificationLog` row per message actually processed. Deliberately the
  *only* place this is logged — not the handlers — since this event pair
  is the one place that uniformly catches both a handler's own thrown
  exception and a handler-*construction* failure (e.g.
  `FirebaseMessagingFactory::create()` throwing because
  `FIREBASE_SERVICE_ACCOUNT_JSON` is missing/malformed — a real incident
  this subscriber exists to make visible; previously such a failure
  discarded the message with zero trace, since
  `config/packages/messenger.yaml` had no `failure_transport`).
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
