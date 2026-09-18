# Routes / Endpoints

All routes traced to `#[Route]` attributes actually opened in
`src/Controller/**`. **API Platform is configured**
(`config/packages/api_platform.yaml`, mounted at `/api` via
`config/routes/api_platform.yaml`) **but zero entities carry
`#[ApiResource]`** (`grep -rln "ApiResource" src/` returns nothing) — API
Platform generates no real routes here. Every `/api/*` route below is a
hand-written controller that happens to share the `/api` prefix with the
unused API Platform mount.

No OpenAPI/Swagger spec file exists in this repo (see "API spec" note at
the end) — this file is the sole source of truth for the route surface.

## `src/Controller/` (top-level)

- **`SyncController`** `#[Route('/api')]` — `POST /api/login` (dead code
  — intercepted by the `json_login` firewall authenticator before
  reaching this method), `POST /api/register`, `POST /api/verify-email`,
  `POST /api/resend-verification`, `POST /api/forgot-password`,
  `POST /api/reset-password`, `POST /api/resend-password-reset`,
  `POST /api/sync`.
- **`LandingController`** — public marketing site (Twig, not API JSON).
  `GET /` (`landing_home`), `GET /delete-account`
  (`landing_delete_account`).
- **`InitializeController`** `#[Route('/api/initialize')]` —
  `POST /starter`, `GET /leagues`, `POST /league/{tier}` (`tier`
  constrained to `\d+`).
- **`AdminSecurityController`** — `GET|POST /admin/login`
  (`admin_login`), `/admin/logout` (`admin_logout` — actually intercepted
  by the firewall logout listener, method body throws `LogicException`).

## `src/Controller/Api/` — player-facing API (all under `/api/...`)

| Controller | Route prefix | Endpoints |
|---|---|---|
| `AccountController` | `/api/account` | `POST /delete` (`IsGranted('ROLE_CLUB')`) |
| `AccountDeletionRequestController` | `/api/account` | `POST /delete-request` |
| `AdminController` | `/api/admin` | `GET /stats` — **stub**, returns a static "not implemented" JSON, no service call |
| `AdminMessageController` | `/api/messages` | `GET /pending`, `POST /{id}/ack` |
| `AppLinksController` | `/api` | `GET /app-links` |
| `ArchetypeController` | `/api/archetypes` | `GET` (single action) |
| `BetaRequestController` | `/api` | `POST /beta-request`, `POST /beta-request/verify` |
| `ClubController` | `/api/club` | `GET /foreign`, `GET /name-options`, `POST /initialize`, `GET /check`, `GET /status` |
| `CommunityStatsController` | `/api/stats` | `GET /most-transfers`, `/most-development`, `/most-seasons`, `/most-trophies` |
| `CompetitionController` | `/api/competitions` | `GET /available`, `POST /{id}/register`, `POST /{id}/resubmit`, `GET /{id}` |
| `EventController` | `/api/events` | `GET /templates` |
| `ExcursionController` | `/api/excursions` | `GET` |
| `FinanceController` | `/api/finance` | `GET /overview`, `GET /investors`, `GET /sponsors`, `POST /sponsors/{id}/terminate` |
| `GameConfigController` | `/api` | `GET /game-config` |
| `InboxController` | `/api/inbox` | `GET ''`, `GET /{id}`, `POST /{id}/accept`, `POST /{id}/reject`, `POST /{id}/read` |
| `LeaderboardController` | `/api` | `GET /leaderboard/{category}` |
| `LeagueController` | `/api/league` | `POST /conclude-season`, `GET /season-history`, `GET /season-history/{season}` |
| `MarketController` | `/api/market` | `GET /data`, `POST /assign`, `POST /consume`, `GET /legacy` |
| `PoolController` | `/api/pool` | `POST /ensure` (`IsGranted('IS_AUTHENTICATED_FULLY')`) |
| `ScoutSearchController` | `/api/scout` | `GET /foreign-clubs`, `GET /search` |
| `StarterConfigController` | `/api` | `GET /starter-config` |
| `TransferLeaderboardController` | `/api/leaderboard/transfers` | `GET /top-sellers`, `GET /most-valuable` |
| `VideoController` | `/api` | `GET /videos/latest` |
| `WorldOverviewController` | `/api` | `GET /world/overview` |

## `src/Controller/Admin/` — custom (non-CRUD) admin actions

- **`DashboardController`** — the EasyAdmin dashboard root, plus ~25
  bespoke routes: `/admin/app-links[/save]`, `/admin/social`,
  `/admin/social/schedule/save`, `/admin/social/test/preview`,
  `/admin/social/test/publish`, `/admin/settings`,
  `/admin/game-config[/save]`, `/admin/starter-config[/save]`,
  `/admin/narrative/content|export|import`,
  `/admin/config/content|export|import`,
  `/admin/npc-clubs/content|save-facility-config|save-size-weights|generate`,
  `/admin/leagues/overview|generate`, `/admin/facilities/overview`,
  `/admin/world/content|export|import`, `/admin/worldpack-cache`,
  `/admin/developer-tools/trigger-age21|cleanup-entities|generate-leaderboards|reset-database|nuclear-reset`,
  `/admin/logs`. Also declares `configureMenuItems()` linking to every
  EasyAdmin CRUD controller below.
- **`AdminStatsController`** — `GET /admin/stats/growth`,
  `GET /admin/stats/leaderboards`,
  `GET /admin/stats/pool/{entity}` (`entity` restricted to
  `players|staff|scouts|agents|world`), `POST /admin/stats/refresh`.
- **`DeleteAdminController`** — `GET /admin/clubs/{id}/delete-info`,
  `POST /admin/clubs/{id}/delete`, `GET /admin/users/{id}/delete-info`,
  `POST /admin/users/{id}/delete`.
- **`FacilityAdminController`** — `POST /admin/facilities/{id}/quick-edit`.
- **`LeagueAdminController`** — `POST /admin/leagues/{id}/quick-edit`.
- **`BetaRequestInviteController`** —
  `GET /admin/beta-requests/{id}/send-invite`.
- **`SocialAuthController`** `#[Route('/admin/social')]` — OAuth flows:
  `POST /{id}/disconnect`, `GET /facebook/connect`,
  `GET /facebook/callback`, `GET /twitter/connect`,
  `GET /twitter/callback`.
- **`WorldPackController`** —
  `POST /admin/worldpack-cache/delete/{id}`,
  `POST /admin/worldpack-cache/delete-country`,
  `GET /admin/worldpack-cache/tiers/{country}`,
  `POST /admin/worldpack-cache/warm-tier`.
- **`PoolConfigController`** —
  `/admin/{player,staff,investor}-pool-config[/save|/generate-chunk|/counts|/clear]`.
- **`CompetitionEntrantCrudController`** — in addition to being an
  EasyAdmin CRUD controller (see below), declares one custom action:
  `GET|POST /admin/competition-entrant/{entrant}/generate-spoof`.

## `src/Controller/Admin/*CrudController.php` — EasyAdmin CRUD

Routes generated dynamically by EasyAdmin (not static `#[Route]`), one
controller per entity, each mapping via `getEntityFqcn()`:
`ActiveCompetition`, `Admin`, `AdminMessage`, `Agent`, `AudienceGroup`,
`BetaRequest`, `Club`, `CompetitionEntrant`, `CompetitionRound`,
`CompetitionTemplate`, `DeletionRequest`, `Excursion`,
`FacilityTemplate`, `GameEventTemplate`, `Guardian`, `Investor`,
`LeaderboardEntry`, `League`, `NpcClub`, `PlayerArchetype`, `Player`,
`RewardTemplate`, `Scout`, `SeasonRecord`, `SeasonSnapshot`,
`SocialPostTemplate`, `Sponsor`, `Staff`, `SyncRecord`,
`TacticalAdvantage`, `Transfer`, `User`. Each gives standard EasyAdmin
CRUD screens (index/detail/edit/new/delete) plus whatever its own
`configureFields()`/`configureActions()` customizes.

## API spec

**No OpenAPI/Swagger spec file exists.** The only Swagger-named files
found are vendored **swagger-ui static assets** bundled by
`api-platform/core` under `public/bundles/apiplatform/swagger-ui/`
(display assets, not a project-authored spec). Since no entity is
`#[ApiResource]`-annotated, API Platform's auto-generated `/api/docs`
spec would in any case be effectively empty. Treat the routes documented
above as the sole source of truth — nothing to cross-check against.
