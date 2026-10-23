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

**Host for every path below:** clients should use `https://api.buildmyclub.co.uk`
(prod) / `https://api.dev.buildmyclub.co.uk` (dev). The `/api` prefix is still
required on those hosts — Caddy does no path rewriting, so `/api/telemetry` works
and `/telemetry` 404s. The `www.`/apex/`dev.` hostnames reach the same container and
serve these paths identically; see `01_overview/output/environment.md`.

## `src/Controller/` (top-level)

- **`SyncController`** `#[Route('/api')]` — `POST /api/login` (dead code
  — intercepted by the `json_login` firewall authenticator before
  reaching this method), `POST /api/register`, `POST /api/verify-email`,
  `POST /api/resend-verification`, `POST /api/forgot-password`,
  `POST /api/reset-password`, `POST /api/resend-password-reset`,
  `POST /api/sync`.
- **`LandingController`** — public marketing site (Twig, not API JSON).
  Renders `LiveTelemetryService::getSnapshot()` server-side into the HTML and
  makes no client-side fetch of its own. Until 2026-10-21 it was the ONLY
  consumer and there was no JSON telemetry endpoint at all; the same aggregate
  is now also served by `Api\TelemetryController` (see below), so a change to
  the snapshot shape has two consumers, not one.
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
| `ArchetypeController` | `/api/archetypes` | `GET` (single action; optional `?lang=` localizes `name`/`description` in place, falling back to the default language on an unknown/disabled code — see `docs/api/translations.md`) |
| `BetaRequestController` | `/api` | `POST /beta-request`, `POST /beta-request/verify` |
| `ClubController` | `/api/club` | `GET /foreign`, `GET /name-options`, `POST /initialize`, `GET /check`, `GET /all`, `GET /status` |
| `ClubHubController` | `/api/club` | `GET /hub`, `GET /competitions` (`IsGranted('ROLE_CLUB')`) — combined Tournament Hub read and club-entered competition history |
| `ClubKitIdentityController` | `/api/club/kit-identity` | `GET`, `POST` (both `IsGranted('ROLE_CLUB')`) — read/partial-update the real club's own kit+badge identity (`homeKitConfig`/`awayKitConfig`/`badgeConfig`); see `docs/api/club-kit-identity.md` |
| `CommunityStatsController` | `/api/stats` | `GET /most-transfers`, `/most-wins`, `/most-development`, `/most-seasons`, `/most-trophies` |
| `CompetitionController` | `/api/competitions` | `GET ''` (filtered list), `GET /champions`, `GET /available`, `GET /active`, `POST /{id}/register`, `POST /{id}/withdraw`, `POST /{id}/resubmit`, `GET /{id}` (supports `ETag` / `If-None-Match` 304) |
| `FriendlyController` | `/api` | `GET /friendlies/challenges`, `GET /club/friendlies/challenges`, `POST /friendlies/challenges`, `POST /friendlies/challenges/{challengeId}/cancel`, `POST /friendlies/challenges/{challengeId}/accept` |
| `MatchController` | `/api` | `GET /matches/{matchId}`, `GET /club/matches` (`?state=upcoming\|recent`), `GET /club/h2h/{opponentClubId}` |
| `CountryController` | `/api/countries` | `GET` — public, no auth, cacheable (1h). One row per `Country` case: `{name: nationality demonym, country: display label, code}` — the canonical mapping clients should read instead of keeping their own copy. See `docs/api/countries.md`. |
| `DeviceTokenController` | `/api/device-tokens` | `POST ''` (`IsGranted('ROLE_CLUB')`, upserts by `deviceToken`), `DELETE /{deviceToken}` — FCM push registration, see `services.md`'s `PushNotificationService` |
| `EventController` | `/api/events` | `GET /templates` (optional `?category=` narrows to one `EventCategory`, e.g. `MATCH_NARRATIVE`; optional `?lang=` localizes `title`/`bodyTemplate` in place, falling back to the default language on an unknown/disabled code — see `docs/api/translations.md`) |
| `ExcursionController` | `/api/excursions` | `GET` (optional `?lang=`, same localize-in-place behavior as `EventController` above, for `title`/`body`; `versionHash` is computed over the localized values) |
| `FinanceController` | `/api/finance` | `GET /overview`, `GET /investors`, `GET /sponsors`, `POST /sponsors/{id}/terminate` |
| `GameConfigController` | `/api` | `GET /game-config` (optional `?lang=` localizes each `facilityTemplates[]` entry's `label`/`description` in place) |
| `InboxController` | `/api/inbox` | `GET ''`, `GET /{id}`, `POST /{id}/accept`, `POST /{id}/reject`, `POST /{id}/read`, `POST /{id}/dismiss` |
| `LanguageController` | `/api/languages` | `GET` — enabled languages, public, no auth |
| `LeaderboardController` | `/api` | `GET /leaderboard/{category}` |
| `LeagueController` | `/api/league` | `POST /conclude-season`, `GET /season-history`, `GET /season-history/{season}` |
| `MarketController` | `/api/market` | `GET /data`, `POST /assign`, `POST /consume`, `GET /legacy` |
| `OwnerAvatarController` | `/api/owner-avatar` | `GET`, `POST` (both `IsGranted('ROLE_CLUB')`) — read/partial-update the account holder's owner identity (name/nationality/gender/dob) + avatar; see "Owner Identity" in CLAUDE.md |
| `PlayerController` | `/api/players` | `GET /foreign` (`IsGranted('ROLE_CLUB')`) — random pool draw of players NOT matching `?country=` code, `?amount=` (default 20, max 200). Read-only, non-consuming. See `docs/api/players-foreign.md`. `GET /youth` (`ROLE_CLUB`) — youth pool draw, ages 12–14, weighted towards `?country=`, `?limit=` (default 200, max 500). **No `tier` param by design** — the draw is stratified across all four `Tier` bands and the device classifies on `potentialTier`. Read-only, non-consuming, so two devices can receive the same player id. Returns the realised `distribution` alongside the players because it DRAWS rather than generates and the pool may be under-seeded. See `docs/api/players-youth.md`. |
| `PoolController` | `/api/pool` | `POST /ensure` (`IsGranted('IS_AUTHENTICATED_FULLY')`) |
| `ScoutSearchController` | `/api/scout` | `GET /foreign-clubs`, `GET /search` (now also takes `?ignore_country=` — excludes that country's nationality, same validation as `PlayerController::foreign()`) |
| `StarterConfigController` | `/api` | `GET /starter-config` |
| `TelemetryController` | `/api/telemetry` | `GET` — **public**, the global live-telemetry aggregate (24h counters + <=20-item incident feed) read from the cached `LiveTelemetrySnapshot` singleton; never aggregates per request. Sends `ETag`/`Last-Modified` off `generatedAt` and answers `If-None-Match` with 304 (snapshot regenerates every 15 min). Deliberately not a verbatim dump: strips the stored `at` (a persisted `DateTimeImmutable` that would serialize as a PHP `{date,timezone_type,timezone}` artifact), `time` (relative string frozen at generation) and `dossier` (web-overlay bulk, excluded by product decision). Contract: `docs/api/live-telemetry.md` |
| `TransferLeaderboardController` | `/api/leaderboard/transfers` | `GET /top-sellers`, `GET /most-valuable` |
| `TranslationController` | `/api/translations` | `GET /{code}`, `GET /{code}/version` — generic UI-copy catalogue, public, no auth; unknown/disabled code is a hard 404 (contrast the fallback-to-default behavior of the `?lang=` params above). See `docs/api/translations.md`. |
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
  `/admin/translations/content|export|import`,
  `/admin/npc-clubs/content|save-facility-config|save-size-weights|generate`,
  `/admin/leagues/overview|generate`, `/admin/facilities/overview`,
  `/admin/world/content|export|import`, `/admin/worldpack-cache`,
  `/admin/developer-tools/trigger-age21|cleanup-entities|generate-leaderboards|reset-database|nuclear-reset`,
  `/admin/logs`, `/admin/telemetry-config` + `POST
  /admin/telemetry-config/rebuild` (read-only view of the
  `LiveTelemetrySnapshot` singleton plus a CSRF-guarded force-rebuild button —
  the service's windows/limits are hardcoded consts, not admin-editable).
  Also declares `configureMenuItems()` linking to every EasyAdmin CRUD
  controller below.
- **`AdminStatsController`** — `GET /admin/stats/growth`,
  `GET /admin/stats/leaderboards`,
  `GET /admin/stats/pool/{entity}` (`entity` restricted to
  `players|staff|scouts|agents|world`), `POST /admin/stats/refresh`.
- **`DeleteAdminController`** — `GET /admin/clubs/{id}/delete-info`,
  `POST /admin/clubs/{id}/delete`, `GET /admin/users/{id}/delete-info`,
  `POST /admin/users/{id}/delete`.
- **`UserSessionController`** — `POST /admin/users/{id}/kill-session`
  (`admin_user_kill_session`) — signs a game account out of every device via
  `SessionRevocationService`. CSRF-guarded, redirects back to the user's detail
  page through `AdminUrlGenerator`. Surfaced as a "Kill Session" button on both
  `/admin/user/{id}` and its edit page (`templates/admin/_kill_session_button.html.twig`),
  alongside a count of unexpired refresh tokens.
- **`FacilityAdminController`** — `POST /admin/facilities/{id}/quick-edit`.
- **`LeagueAdminController`** — `POST /admin/leagues/{id}/quick-edit`,
  `POST /admin/leagues/tier-defaults/bulk-edit` (`admin_league_tier_defaults_bulk_edit`
  — saves the per-tier League Tier Defaults table onto `GameConfig`, and
  optionally cascades it onto checked existing `League` rows).
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
- **`NotificationDebugController`** — manual controls for the
  push-notification pipeline, kept separate from `DashboardController`
  per its own "developer tools" precedent: `GET
  /admin/notifications/debug` (reached via `/admin?routeName=...`, same
  as `admin_logs`); `POST
  /admin/notifications/debug/force-process-queue` (runs
  `messenger:consume async` in-process, same `Application($kernel)` +
  `BufferedOutput` shape as `DashboardController::cleanupEntities()`);
  `POST /admin/notifications/debug/validate-firebase`
  (`FirebaseConnectionValidator`); `POST
  /admin/notifications/debug/trigger/{type}/{club}` (`type` restricted
  to `ROUND_DRAWN|NEW_REGISTRANT|MATCH_RESULT|ADMIN_MESSAGE`,
  `Club $club` a Doctrine-typed route parameter) — dispatches a
  clearly-marked `[TEST]`-prefixed synthetic push to one club, for
  testing raw FCM delivery without a real competition/message behind it.
- **`NarrativeTranslationController`** — the "Translations" quick-edit
  screen linked from `GameEventTemplateCrudController`/
  `FacilityTemplateCrudController`/`ExcursionCrudController`'s row actions:
  `GET /admin/narrative-translations/{entityType}/{id}` (reached via
  `/admin?routeName=admin_narrative_translation_edit&routeParams[...]`,
  since it renders an `@EasyAdmin`-extending template directly),
  `POST /admin/narrative-translations/{entityType}/{id}/save` (plain
  route — always redirects, never renders, so exempt from that rule).
  `DashboardController` additionally gains the
  `/admin/translations/content|export|import` trio (generic UI-copy bulk
  import/export, mirroring `/admin/config/content|export|import`) and a
  "Translations" submenu in `configureMenuItems()`.

## `src/Controller/Admin/*CrudController.php` — EasyAdmin CRUD

Routes generated dynamically by EasyAdmin (not static `#[Route]`), one
controller per entity, each mapping via `getEntityFqcn()`:
`ActiveCompetition`, `Admin`, `AdminMessage`, `Agent`, `AudienceGroup`,
`BetaRequest`, `Club`, `CompetitionEntrant`, `CompetitionRound`,
`CompetitionTemplate`, `DeletionRequest`, `Excursion`,
`FacilityTemplate`, `GameEventTemplate`, `Guardian`, `Investor`,
`Language`, `LeaderboardEntry`, `League`, `NotificationLog`, `NpcClub`,
`PlayerArchetype`, `Player`, `RewardTemplate`, `Scout`, `SeasonRecord`,
`SeasonSnapshot`, `SocialPostTemplate`, `Sponsor`, `Staff`, `SyncRecord`,
`TacticalAdvantage`, `Transfer`, `Translation`, `TranslationKey`, `User`,
`UserLedger`. Each gives standard EasyAdmin CRUD screens (index/detail/
edit/new/delete) plus whatever its own
`configureFields()`/`configureActions()` customizes.
`NotificationLogCrudController`/`UserLedgerCrudController` are read-only
(disable new/edit/delete), styled directly on
`DeletionRequestCrudController`.

`GameEventTemplateCrudController`/`FacilityTemplateCrudController`/
`ExcursionCrudController` each add a row action ("Translations", linking to
`admin_narrative_translation_edit` above). `TranslationKeyCrudController`/
`TranslationCrudController` are generic-UI-copy-only — their
`createIndexQueryBuilder()` filters out narrative-linked rows, which are
managed only via the row action above. `TranslationKeyCrudController`'s
index is additionally grouped by key with a per-enabled-language
presence-icon column and an "Incomplete translations" filter
(`App\Filter\IncompleteTranslationFilter`); its edit page
(`crud/edit` → `admin/translation_key_edit.html.twig`) adds a
per-language AJAX-save grid hitting its own custom route:
`POST /admin/translation-key/{id}/save-language/{code}` (name
`admin_translation_key_save_language`) — always returns JSON, never
renders an `@EasyAdmin`-extending template itself, so (per this
directory's own `CLAUDE.md`) it's exempt from the `/admin?routeName=...`
wrapping rule. See `controllers.md` for the full behavior.

## API spec

**No OpenAPI/Swagger spec file exists.** The only Swagger-named files
found are vendored **swagger-ui static assets** bundled by
`api-platform/core` under `public/bundles/apiplatform/swagger-ui/`
(display assets, not a project-authored spec). Since no entity is
`#[ApiResource]`-annotated, API Platform's auto-generated `/api/docs`
spec would in any case be effectively empty. Treat the routes documented
above as the sole source of truth — nothing to cross-check against.
