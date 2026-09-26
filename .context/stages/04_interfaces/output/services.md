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
  see and records that it has seen them.
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
  and schedules its first round the instant the last slot fills.
- **`Competition/CompetitionRegistrationService`** — registers entrants,
  using `SELECT ... FOR UPDATE` on the `ActiveCompetition` row to
  serialize concurrent last-slot registrations.
- **`Competition/CompetitionRoundProcessorService`** — core scheduling/
  idempotency/bracket-advancement logic behind the
  `app:competition:process-rounds` command (runs every 1 minute via cron
  — see `02_architecture/output/structure.md`).
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
  config (formations, reputation tiers, trophy colours, city sizes,
  kit+badge `identity`).
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
- **`MatchEngine/DeterministicEngine`** — deterministic match resolution;
  playing style affects outcome, formation is display-only.
- **`MatchEngine/MatchEngineInterface`** — contract for match engines
  (input: `CompetitionEntrant`/`CompetitionFixture`).
- **`MatchEngine/MatchEngineRegistry`** — tagged-iterator dispatcher
  selecting a `MatchEngineInterface` by `MatchEngineIdentifier` (see
  `config/services.yaml`'s `_instanceof` binding).
- **`MatchEngine/MatchEngineResult`** — plain value object for a match
  result, persisted by the caller into `CompetitionResult`.
- **`NameGeneratorService`** — generates names (players/staff/clubs).
- **`NarrativeImportExportService`** — imports/exports narrative content
  (events, playing styles, facility/tactical/archetype templates).
- **`NpcClubGenerationService`** — generates NPC clubs using
  `FacilityTemplateRepository`, `GameConfigRepository`, `LeagueService`;
  each club also gets a `generateIdentity()`-produced home+away kit+badge
  config (`randomKitVariant()` run twice). The home kit's colors become the
  club's `primaryColor`/`secondaryColor` via `NpcClub::setIdentity()`'s
  auto-sync — there's no separate color-pair generator any more (see
  CLAUDE.md's "Kit & Badge Identity").
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
