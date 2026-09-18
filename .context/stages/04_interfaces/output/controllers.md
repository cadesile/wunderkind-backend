# Controller Responsibilities

Routes are in `routes.md` — this file covers what each controller
actually *does* (which service/repo it calls), not restated route paths.

## `src/Controller/` (top-level)

- **`SyncController`** — auth lifecycle (register/verify/forgot-password/
  reset) plus the core `/api/sync` endpoint. Uses
  `EmailVerificationService`, `SyncService`,
  `EmailVerificationRepository`, JWT (`JWTTokenManagerInterface`, Lexik
  events), `UserPasswordHasherInterface`.
- **`LandingController`** — server-renders the public marketing site by
  calling `WorldOverviewService` directly (not over HTTP), plus
  `YouTubeFeedService`, `GameConfigRepository`, `ExcursionRepository`,
  `ArchetypeShowcaseService`, `LiveTelemetryService`.
- **`InitializeController`** — new-club/world bootstrap flow. Uses
  `ClubResolver`, `PlayerRepository`, `LeagueRepository`,
  `StarterPackService`, `WorldInitializationService`,
  `WorldPackCacheService`.
- **`AdminSecurityController`** — renders the admin login form; actual
  login/logout logic is handled by the security firewall, not this
  controller's method bodies.

## `src/Controller/Api/`

- **`AccountController`** / **`AccountDeletionRequestController`** —
  account deletion (direct and request-based flows). Call
  `AccountDeletionService`, `ClubRepository`, `DeletionRequestRepository`.
- **`AdminController`** — stub only; no service call yet.
- **`AdminMessageController`** — surfaces pending operator announcements
  and records acknowledgement. Uses `ClubResolver`,
  `AdminMessageRepository`, `AdminMessageService`.
- **`AppLinksController`**, **`GameConfigController`**,
  **`StarterConfigController`**, **`VideoController`** — thin
  read-through controllers over `GameConfigRepository`/
  `StarterConfigRepository`/`FacilityTemplateRepository`/
  `FacilityImageResolver`/`YouTubeFeedService`.
- **`ArchetypeController`** — single read action over
  `PlayerArchetypeRepository`.
- **`BetaRequestController`** — beta signup + verification, via
  `BetaRequestRepository`, `EmailVerificationService`.
- **`ClubController`** — club lookup/initialization/status checks via
  `ClubResolver` (constructor-injected) plus method-injected repos as
  needed.
- **`CommunityStatsController`** — four read-only leaderboard views via
  `CommunityStatsService`.
- **`CompetitionController`** — competition discovery, registration, and
  resubmission. Deps: `ClubResolver`, `CompetitionTemplateRepository`,
  `ActiveCompetitionRepository`, `CompetitionEntrantRepository`,
  `CompetitionRoundRepository`, `CompetitionFixtureRepository`,
  `EligibilityEvaluator`, `SnapshotValidator`,
  `CompetitionRegistrationService`.
- **`EventController`** — templates listing via
  `GameEventTemplateRepository`.
- **`ExcursionController`** — listing via `ExcursionRepository`.
- **`FinanceController`** — club finance overview/investors/sponsors,
  including sponsor termination. Deps: `ClubResolver`,
  `InvestorRepository`, `SponsorRepository`, and (per-method) other
  services as used.
- **`InboxController`** — inbox listing/accept/reject/read-marking. Uses
  `ClubResolver`, `InboxMessageRepository`, `InboxService`.
- **`LeaderboardController`** — `LeaderboardCalculationService`.
- **`LeagueController`** — season conclusion and history. Deps:
  `ClubResolver`, `LeagueService`, `SeasonSnapshotRepository`,
  `SeasonRecordRepository`.
- **`MarketController`** — market listing + assign/consume actions,
  including a legacy read path. Uses `ClubResolver`, `MarketDataService`,
  `MarketPoolService`, plus `AgentRepository`, `InvestorRepository`,
  `PlayerRepository`, `ScoutRepository`, `SponsorRepository`,
  `StaffRepository`.
- **`PoolController`** — idempotently tops up the unassigned-player pool
  for a nationality via `MarketPoolService` + `PlayerRepository`.
- **`ScoutSearchController`** — foreign-club listing (`NpcClubRepository`)
  and player search (`PlayerRepository`).
- **`TransferLeaderboardController`** — `TransferLeaderboardService`.
- **`WorldOverviewController`** — `WorldOverviewService` (same service
  `LandingController` calls directly server-side).

## `src/Controller/Admin/` — custom actions

- **`DashboardController`** — EasyAdmin dashboard root and the largest
  single controller: config import/export, social scheduling, developer
  tools (age-trigger, entity cleanup, leaderboard regen, database reset/
  "nuclear reset"), logs. Also wires the CRUD menu.
- **`AdminStatsController`** — dashboard growth/leaderboard/pool stats
  via `DashboardStatsService`.
- **`DeleteAdminController`** — club/user deletion with confirmation info,
  using `EntityManagerInterface` + `CsrfTokenManagerInterface` directly
  (no dedicated service layer).
- **`FacilityAdminController`** / **`LeagueAdminController`** —
  quick-edit actions, `EntityManagerInterface` only.
- **`BetaRequestInviteController`** — sends beta invites via
  `EmailVerificationService` + `EntityManagerInterface`.
- **`SocialAuthController`** — Facebook/Twitter OAuth connect/callback/
  disconnect for admin-authored social posting. Uses
  `SocialAccountConnectionRepository`, `TokenEncryptionService`,
  `HttpClientInterface`, injected platform client id/secret/redirect-uri
  params.
- **`WorldPackController`** — world-pack cache admin (delete/warm tiers)
  via `CountryWorldPackCacheRepository`, `WorldPackCacheService`,
  `WorldInitializationService`, `LeagueRepository`.
- **`PoolConfigController`** — player/staff/investor pool config screens
  and generation via `PoolConfigRepository`, `MarketPoolService`.
- **`CompetitionEntrantCrudController`** — standard CRUD plus a custom
  "generate spoof entrant" admin action (see
  `CompetitionSpoofEntrantService` in `services.md`).

## `src/Controller/Admin/*CrudController.php`

31 EasyAdmin CRUD controllers, one per entity (full list in
`routes.md`). Each provides standard index/detail/edit/new/delete screens
via `configureFields()`/`configureActions()` — individual field
configuration wasn't traced controller-by-controller (not architecturally
significant beyond the pattern itself).
