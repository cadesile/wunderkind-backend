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
- **`DeviceTokenController`** — FCM push-token registration
  (`POST /api/device-tokens`, upserts by token; `DELETE /{deviceToken}`
  for logout). Uses `UserDeviceRepository`. See `services.md`'s
  `Notification/PushNotificationService`.
- **`AppLinksController`**, **`GameConfigController`**,
  **`StarterConfigController`**, **`VideoController`** — thin
  read-through controllers over `GameConfigRepository`/
  `StarterConfigRepository`/`FacilityTemplateRepository`/
  `FacilityImageResolver`/`YouTubeFeedService`.
- **`ArchetypeController`** — single read action over
  `PlayerArchetypeRepository`.
- **`LanguageController`** — single read action over `LanguageRepository`.
- **`TranslationController`** — generic UI-copy catalogue + version-hash
  endpoints, via `LanguageRepository` + `TranslationCatalogueService`.
- **`BetaRequestController`** — beta signup + verification, via
  `BetaRequestRepository`, `EmailVerificationService`.
- **`ClubController`** — club lookup/initialization/status checks via
  `ClubResolver` (constructor-injected) plus method-injected repos as
  needed. `GET /all` is the one action here that deliberately does **not**
  go through `ClubResolver` — it returns every club the account owns
  (`ClubRepository::findAllByUser()`) with a brief identity + last-sync
  summary per club (`SyncRecordRepository::findLatestValid()` for
  `leaguePosition`/`form`), for a save-slot picker. See
  `docs/api/club-list.md`.
- **`CommunityStatsController`** — four read-only leaderboard views via
  `CommunityStatsService`.
- **`CompetitionController`** — competition discovery, registration, and
  resubmission. Deps: `ClubResolver`, `CompetitionTemplateRepository`,
  `ActiveCompetitionRepository`, `CompetitionEntrantRepository`,
  `CompetitionRoundRepository`, `CompetitionFixtureRepository`,
  `EligibilityEvaluator`, `SnapshotValidator`,
  `CompetitionRegistrationService`.
- **`EventController`** — templates listing via
  `GameEventTemplateRepository`; `?lang=` localization via
  `LanguageRepository` + `NarrativeTranslationService`.
- **`ExcursionController`** — listing via `ExcursionRepository`; `?lang=`
  localization via `LanguageRepository` + `NarrativeTranslationService`.
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
- **`NotificationDebugController`** — kept deliberately separate from
  `DashboardController` despite following the exact same "developer
  tools" shape (CSRF-protected POST, in-process `Application($kernel)` +
  `BufferedOutput` for the force-process-queue action). Force-triggers
  each of the 4 push types at an admin-chosen `Club`
  (`PushNotificationService`) and runs `FirebaseConnectionValidator`'s
  two-tier check — both added after a real incident where a missing
  `FIREBASE_SERVICE_ACCOUNT_JSON` secret silently discarded every push
  send with zero trace.
- **`NarrativeTranslationController`** — the admin "Translations"
  quick-edit screen for one GameEventTemplate/FacilityTemplate/Excursion
  row, via `NarrativeTranslationService` + `LanguageRepository`.

## `src/Controller/Admin/*CrudController.php`

36 EasyAdmin CRUD controllers, one per entity (full list in
`routes.md`). Each provides standard index/detail/edit/new/delete screens
via `configureFields()`/`configureActions()` — individual field
configuration wasn't traced controller-by-controller (not architecturally
significant beyond the pattern itself).

**`NotificationLogCrudController`** — read-only (new/edit/delete
disabled), styled directly on `DeletionRequestCrudController`: a
`ChoiceField` status badge + `ChoiceFilter`, `TextFilter` on
`messageType`/`summary`/`errorMessage`, and a `CodeEditorField` JSON
detail view (`detailJsonPretty`) shown only on the detail page.

**`UserLedgerCrudController`** — read-only (new/edit/delete disabled), same
`NotificationLogCrudController`/`DeletionRequestCrudController` pattern — an
audit trail of `UserLedger` rows, filterable by `user`/`club`
(`EntityFilter`), with `balanceBeforePence`/`balanceAfterPence` shown so a
mismatch between adjacent rows for the same user is visibly a bug.

**`UserCrudController`** overrides two standard actions, not just fields:
- `detail()` renders a fully custom `admin/user_profile.html.twig` instead of
  EasyAdmin's generated detail screen — header (verification/join/last-login),
  a plain table of the user's clubs (name, country, last sync week/date,
  created). Same "bypass EasyAdmin's own rendering, call `$this->render()`
  directly" pattern as `ClubCrudController::detail()` → `club_profile.html.twig`.
- `edit()` does **not** bypass EasyAdmin's rendering — it calls `parent::edit()`,
  adds `clubsSummary`/`clubKitConfigs`/`overallBalancePence` onto the returned
  `KeyValueStore`, and `configureCrud()` points `crud/edit` at
  `admin/user_edit.html.twig`, which `{% extends '@EasyAdmin/crud/edit.html.twig' %}`
  and overrides only `content_header_wrapper` (calling `{{ parent() }}` first) —
  so the real edit form (`main` block) is completely untouched and still submits
  normally. The injected panel: per-club identity (name, country), composited
  home/away kit + badge previews (`public/assets/kit-compositor.js`'s
  `composeKitSvg()`, 'small' size — same renderer `club_profile.html.twig` uses
  at 'large'), last-sync badges (week, league position, W/D/L form strip via
  the shared `resultBadge` macro in `admin/_macros.html.twig`), each club's
  `totalCareerEarnings` vs. dividends drawn from it
  (`UserLedgerRepository::getTotalDividendsByClub()`), and the user's overall
  cross-club balance (`UserLedgerRepository::getCurrentBalance()`). Same
  "override one block of a standard CRUD template to inject a computed panel"
  technique as `PlayerCrudController::index()`'s `playerSummary` panel via
  `overrideTemplate('crud/index', ...)` + `content_header_wrapper`, just
  applied to `crud/edit` instead of `crud/index`.

One exception worth noting: **`AdminMessageCrudController`** overrides
`persistEntity()`/`updateEntity()` to dispatch
`ResolveAdminMessageAudienceForPushMessage` via Messenger the first time
a message is saved Active with `sendAsPush` checked (`pushSentAt` guards
against resending on later edits) — see `services.md`'s
`Notification/` entries.
