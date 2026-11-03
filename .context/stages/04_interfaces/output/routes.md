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
- **`Api\TelemetryController`** `GET /api/telemetry` — also gained
  `stats.promotions` / `stats.relegations` (the 7-day Table Turnover figure split by
  direction, `tableTurnoverWindowDays` applies to all three) and a per-feed-item
  `trophy` (TrophyConfig, set only on `CUP` items). All three are ADDITIVE:
  `stats.tableTurnover` stays, and equals the two added together.
- **`LandingController`** — public marketing site (Twig, not API JSON).
  Renders `LiveTelemetryService::getSnapshot()` server-side into the HTML and
  makes no client-side fetch of its own. Until 2026-10-21 it was the ONLY
  consumer and there was no JSON telemetry endpoint at all; the same aggregate
  is now also served by `Api\TelemetryController` (see below), so a change to
  the snapshot shape has two consumers, not one.
  `GET /` (`landing_home`), `GET /delete-account`
  (`landing_delete_account`).
  Section services it renders server-side: `WorldOverviewService`,
  `ArchetypeShowcaseService`, `LiveTelemetryService`, `ClubSpotlightService`,
  `OwnerSpotlightService`, `PeriodAwardsBoardService`,
  `CupResultsBoardService` (`#cup-results`) and `CupTelemetryService` (the CUPS
  band inside `#telemetry`). `PeriodAwardsBoardService` and
  `CupResultsBoardService` gate their own section AND its nav link on the same
  emptiness check, so a board with no data leaves no dead anchor in the header.
  `CupTelemetryService` is the one read that is NOT a cached snapshot — see
  services.md for why its countdowns cannot be.
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
| `ClubController` | `/api/club` | `GET /foreign`, `GET /name-options`, `POST /initialize`, `GET /check` (**authentication-only, the one exception on this controller** — see below), `GET /all`, `GET /status`. `GET /status` also carries **`debugLoggingEnabled`** (`global GameConfig switch \|\| the account's own User::$debugLoggingEnabled`, via `DebugLoggingResolver`) — mirrored from `GET /api/game-config` deliberately, because that one is public and so an anonymous cold-boot fetch there can only ever see the global half. This endpoint is `ROLE_CLUB`, so the per-account override always applies: it is the reliable read for a signed-in client. Client contract: `docs/api/debug-logging.md` |
| `ClubHubController` | `/api/club` | `GET /hub`, `GET /competitions` (`IsGranted('ROLE_CLUB')`) — combined Tournament Hub read and club-entered competition history |
| `ClubKitIdentityController` | `/api/club/kit-identity` | `GET`, `POST` (both `IsGranted('ROLE_CLUB')`) — read/partial-update the real club's own kit+badge identity (`homeKitConfig`/`awayKitConfig`/`badgeConfig`); see `docs/api/club-kit-identity.md` |
| `CommunityStatsController` | `/api/stats` | `GET /most-transfers`, `/most-wins`, `/most-development`, `/most-seasons`, `/most-trophies` |
| `CompetitionController` | `/api/competitions` | `GET ''` (filtered list), `GET /champions`, `GET /available`, `GET /active`, `POST /{id}/register`, `POST /{id}/withdraw`, `POST /{id}/resubmit` (**all three answer in one shape** — top-level `status` is the entrant's standing and the entry is nested under `entrant`; `/resubmit`'s `status` used to be the literal `'accepted'`, changed while tournaments were pre-launch. They return `entrant.submissionStatus` — `pending`/`confirmed`/`failed`, the state of the queued squad write, a **separate axis** from `status`; mirrored per-viewer as `viewer.snapshot.status`/`settled` and deliberately absent from other clubs' entrant summaries because those are cached and the fingerprint excludes it — see `docs/api/competition-registration-status.md`), `GET /{id}` (supports `ETag` / `If-None-Match` 304; served from a prebuilt `competition_response_cache` document with only the `viewer` key and `serverTime` composed per request — see `CompetitionResponseBuilder`/`CompetitionResponseCacheService` in `services.md`. **No per-fixture `result` block** — match detail is `GET /api/matches/{id}`, reached via each fixture's `matchId`; see `docs/api/competition-bracket-no-embedded-result.md`. Each round carries **`roundId`** (null on a `future` placeholder), which is how a `ROUND_DRAWN`/`ROUND_RESOLVING_SOON` push — which carries no fixture id — is mapped to a round; see `docs/api/competition-round-id.md`) |
| `FriendlyController` | `/api` | `GET /friendlies/challenges`, `GET /club/friendlies/challenges`, `POST /friendlies/challenges`, `POST /friendlies/challenges/{challengeId}/cancel`, `POST /friendlies/challenges/{challengeId}/accept` |
| `MatchController` | `/api` | `GET /matches/{matchId}`, `GET /club/matches` (`?state=upcoming\|recent`), `GET /club/h2h/{opponentClubId}` |
| `CountryController` | `/api/countries` | `GET` — public, no auth, cacheable (1h). One row per `Country` case: `{name: nationality demonym, country: display label, code}` — the canonical mapping clients should read instead of keeping their own copy. See `docs/api/countries.md`. |
| `DeviceTokenController` | `/api/device-tokens` | `POST ''` (`IsGranted('ROLE_CLUB')`, upserts by `deviceToken`), `DELETE /{deviceToken}` — FCM push registration, see `services.md`'s `PushNotificationService` |
| `EventController` | `/api/events` | `GET /templates` (optional `?category=` narrows to one `EventCategory`, e.g. `MATCH_NARRATIVE`; optional `?lang=` localizes `title`/`bodyTemplate` in place, falling back to the default language on an unknown/disabled code — see `docs/api/translations.md`) |
| `ExcursionController` | `/api/excursions` | `GET` (optional `?lang=`, same localize-in-place behavior as `EventController` above, for `title`/`body`; `versionHash` is computed over the localized values) |
| `FinanceController` | `/api/finance` | `GET /overview`, `GET /investors`, `GET /sponsors`, `POST /sponsors/{id}/terminate` |
| `GameConfigController` | `/api` | `GET /game-config` (optional `?lang=` localizes each `facilityTemplates[]` entry's `label`/`description` in place). **Public, but one key is account-dependent:** `debugLoggingEnabled` is served as `global GameConfig switch || the requesting User's own debugLoggingEnabled` (`resolveDebugLoggingEnabled()`) — same key, same boolean shape as before, so no client change was needed. The response is `Cache-Control: private` for that reason; nothing caches this endpoint today and that header is what keeps a future CDN from serving one account's flag to another. The per-user half needs a Bearer token on the request, so an anonymous call gets the global value only — which is why `GET /api/club/status` mirrors the same key for signed-in clients (see `ClubController` above). Both endpoints go through `DebugLoggingResolver`; `GameConfigDebugFlagTest` asserts they agree for the same account. Client contract: `docs/api/debug-logging.md` |
| `InboxController` | `/api/inbox` | `GET ''`, `GET /{id}`, `POST /{id}/accept`, `POST /{id}/reject`, `POST /{id}/read`, `POST /{id}/dismiss` |
| `LanguageController` | `/api/languages` | `GET` — enabled languages, public, no auth |
| `LeaderboardController` | `/api` | `GET /leaderboard/{category}` — `?period=` accepts `all-time` plus a recognised period key (`2026-W44`/`2026-M10`/`2026-Q4`); **anything else returns the same empty 200**, short-circuited before the database so an arbitrary string cannot mint a cache key and trigger a `recalculate()` *write*. A closed period is served from its archive. **Periodic leaderboard awards add NO new API route** — the prize arrives through the unchanged `GET /api/inbox` + `POST /api/inbox/{id}/accept`. |
| `LeagueController` | `/api/league` | `POST /conclude-season`, `GET /season-history`, `GET /season-history/{season}` |
| `MarketController` | `/api/market` | `GET /data`, `POST /assign`, `POST /consume`, `GET /legacy` |
| `OwnerAvatarController` | `/api/owner-avatar` | `GET`, `POST` (both `IsGranted('ROLE_CLUB')`) — read/partial-update the account holder's owner identity (name/nationality/gender/dob) + avatar; see "Owner Identity" in CLAUDE.md |
| `PlayerController` | `/api/players` | `GET /foreign` (`IsGranted('ROLE_CLUB')`) — random pool draw of players NOT matching `?country=` code, `?amount=` (default 20, max 200). Read-only, non-consuming. See `docs/api/players-foreign.md`. `GET /youth` (`ROLE_CLUB`) — youth pool draw, ages 12–14, weighted towards `?country=`, `?limit=` (default 200, max 500). **No `tier` param by design** — the draw is stratified across all four `Tier` bands and the device classifies on `potentialTier`. Read-only, non-consuming, so two devices can receive the same player id. Returns the realised `distribution` alongside the players because it DRAWS rather than generates and the pool may be under-seeded. See `docs/api/players-youth.md`. |
| `PoolController` | `/api/pool` | `POST /ensure` (`IsGranted('IS_AUTHENTICATED_FULLY')`) |
| `ScoutSearchController` | `/api/scout` | `GET /foreign-clubs`, `GET /search` (now also takes `?ignore_country=` — excludes that country's nationality, same validation as `PlayerController::foreign()`) |
| `StarterConfigController` | `/api` | `GET /starter-config` — public, pre-credentials. Carries the optional `tutorial` block (`starter_config.setup_tutorial_config`, implemented 2026-11-02): first-run cards for the app's new-game setup, admin-edited at `/admin/starter-config`, checked by `app:check-setup-tutorial`. **Mistakes in it are silent** — the client's parser cannot throw, so a bad item is dropped without a word. See `docs/api/setup-tutorial.md` |
| `TelemetryController` | `/api/telemetry` | `GET` — **public**, the global live-telemetry aggregate (24h counters + <=20-item incident feed) read from the cached `LiveTelemetrySnapshot` singleton; never aggregates per request. Sends `ETag`/`Last-Modified` off `generatedAt` and answers `If-None-Match` with 304 (snapshot regenerates every 15 min). Deliberately not a verbatim dump: strips the stored `at` (a persisted `DateTimeImmutable` that would serialize as a PHP `{date,timezone_type,timezone}` artifact), `time` (relative string frozen at generation) and `dossier` (web-overlay bulk, excluded by product decision). Contract: `docs/api/live-telemetry.md` |
| `TransferLeaderboardController` | `/api/leaderboard/transfers` | `GET /top-sellers`, `GET /most-valuable` |
| `TranslationController` | `/api/translations` | `GET /{code}`, `GET /{code}/version` — generic UI-copy catalogue, public, no auth; unknown/disabled code is a hard 404 (contrast the fallback-to-default behavior of the `?lang=` params above). See `docs/api/translations.md`. |
| `VideoController` | `/api` | `GET /videos/latest` |
| `WorldOverviewController` | `/api` | `GET /world/overview` |

### `GET /api/club/check` is authentication-only, and needs two exceptions to stay that way

The endpoint answers "does this account have a club?", so an account with no club has to be
able to ask it — `404 {"exists": false, "reason": "club_not_found"}` **is** the answer, and
`200 {"exists": true, "clubId": ...}` the other one. It was gated on `ROLE_CLUB`, which made
the 404 branch unreachable for exactly those callers: Symfony denies before the controller
runs, so they got 403. The client reads 404 as "no club yet" and anything else as "the server
refused this account", so the two are not interchangeable — that 403 is what surfaced as a
blank sign-in screen (`wunderkind-app/docs/backend-notes/2026-11-01-role-club-403.md`).

**Both gates had to be relaxed and either one alone changes nothing:** the `#[IsGranted]` on
the method, *and* `access_control`'s `^/api/club/` rule, which also matches
`/api/club/check`. The `^/api/club/check$` entry therefore sits **ahead** of it (first match
wins). It also catches `ClubMismatchException` and answers the same 404 (`reason:
`club_not_owned``), which is the other door to the same unreadable 403: that exception is an
`AccessDeniedHttpException`, so an `X-Club-Id` naming a club the account does not own was
refusing the account rather than answering it — for a client holding nothing worse than a
stale id, which a half-finished club creation leaves behind. Everywhere else the exception's
403 stays correct; it guards a write against landing on another save's club, and `/check`
writes nothing. No disclosure either way: `ClubResolver` throws identically for an unknown id
and another user's, so the response cannot distinguish them.

Scope is `/check` only — every other `/api/club/*` route is still `ROLE_CLUB`, which is
right: they are about a club, not about whether one exists. Guarded by
`tests/Controller/Api/ClubCheckAccessTest.php`, whose fixtures deliberately store no roles at
all, so it covers the firewall half of the `ROLE_CLUB` lockout below as well. Client contract:
`docs/api/role-club-403.md`.

### `ROLE_CLUB` is guaranteed by the entity, not read from `user.roles`

`User::getRoles()` appends `ROLE_CLUB` unconditionally, the way `Admin::getRoles()` hardcodes
`ROLE_ADMIN`. This is not cosmetic: there is no `role_hierarchy` in `security.yaml`, and the
column has twice held something that granted nothing — `[]` for accounts registered before
`6dc85ab` (2026-03) and the since-deleted `ROLE_ACADEMY` between `6dc85ab` and `2a9e6cd`
(2026-04). Login is gated on `isVerified()` rather than on roles, so such an account
authenticated, received a valid JWT, and then got 403 from every `roles: ROLE_CLUB` route in
`access_control` — indistinguishable, client-side, from a refused account. Roles are resolved
from the user provider on every request (`JWTAuthenticator::loadUser()` → entity provider's
`loadUserByIdentifier()`, since `app_user_provider` is not `PayloadAwareUserProviderInterface`),
so a token minted before the fix starts working without re-authentication. See
`03_data/output/entities.md` for the entity-side note.

## `src/Controller/Admin/` — custom (non-CRUD) admin actions

- **`LeaderboardAwardCloseController`** — `POST
  /admin/leaderboard-award/{definition}/close-now` (`admin_leaderboard_award_close_now`),
  CSRF-guarded, posted by the **Close now** panel on an award definition's detail page.
  POST-only on purpose: closing a period prunes live rows, pays prizes and sends inbox
  mail, so a crawler or a link-prefetcher must not be able to trigger it — a GET returns
  405. There is no crontab under Lando, so this is how the close job is exercised by hand.
  Redirects to EasyAdmin's own `admin_leaderboard_award_definition_detail` route, which
  already builds the crud context on a direct match (only this action's own route needs the
  `/admin?routeName=…` forwarding rule from `src/Controller/Admin/CLAUDE.md`).
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
  `/admin/developer-tools/purge-spoof-clubs` (`POST`, CSRF-guarded — the
  Settings screen's "Clear Spoof Clubs" button, running
  `app:clubs:purge-spoof` — **since 2026-11-02 this is the ONLY scheduled-ish caller there
  is**, the deploy workflows having dropped their every-deploy run of the same command
  (it was deleting the opposition out of finished cups; see `03_data/output/migrations.md`);
  unlike the older Developer Tools buttons beside it
  this one posts to and redirects through `/admin?routeName=...`, which is what
  `src/Controller/Admin/CLAUDE.md` requires of any action rendering an
  `@EasyAdmin`-extending template),
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
- **`StarterPackCheckController`** — `GET /admin/starter-pack-check`
  (`admin_starter_pack_check`, linked under Configuration). Read-only
  "can a new club in this country be given a starter pack right now?"
  diagnostic — counts only, consumes nothing, safe against production.
  Country + tier come from the query string so a result is a linkable URL.
  CLI twin: `app:starter-pack:check <CODE> [--tier=N]`.
  Also owns **`POST /admin/starter-pack-check/warm`**
  (`admin_starter_pack_warm`) — the "Generate starter pack" button beside Run
  check, which runs `app:pool:warm <CODE>` for the checked country and redirects
  back into the check so the effect is visible rather than claimed. **This is
  the one control on that screen that writes**, hence POST + CSRF + routing
  through `/admin?routeName=...`, and hence its country coming from the
  server's last-checked value rather than the form select (so it cannot act on
  a dropdown changed but never submitted). It is hidden until a check has been
  run. The action raises `set_time_limit()` deliberately: a cold warm generates
  500 players, 150 staff and 50 scouts for the nationality, measured ~20s, close
  enough to PHP's 30s default that a slower box would 504 mid-run and leave a
  half-warmed pool with no output. Warming tops up to minimums, so a repeat
  press is safe.
- **`CompetitionEntrantCrudController`** — also owns
  `/admin/competition-entrant/{entrant}/generate-spoof`
  (`admin_competition_entrant_generate_spoof`, `GET|POST`): the detail page's
  "Generate Spoof" action, which clones the entrant being viewed into a new
  randomised spoof entrant in its own competition —
  `createSpoofEntrantFromSnapshot(..., randomise: true)`, i.e. the Create Spoof
  screen's process without the paste. GET confirms, POST acts behind a CSRF
  token (EasyAdmin actions are plain links, so a GET must not create data), and
  the action is hidden unless that competition is `REGISTERING`. Two path
  segments under the entrant id so it does not collide with EasyAdmin's own
  `/admin/competition-entrant/{entityId}` detail route. In addition to being an
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
`FacilityTemplate`, `FriendlyChallenge`, `GameEventTemplate`, `Guardian`, `Investor`,
`Language`, `LeaderboardAwardDefinition`, `LeaderboardEntry`,
`LeaderboardPeriodArchive`, `LeaderboardPeriodArchiveEntry`, `League`,
`NotificationLog`, `NpcClub`,
`PlayerArchetype`, `Player`, `RewardTemplate`, `Scout`, `SeasonRecord`,
`SeasonSnapshot`, `SocialPostTemplate`, `Sponsor`, `Staff`, `SyncRecord`,
`TacticalAdvantage`, `Transfer`, `Translation`, `TranslationKey`, `User`,
`UserLedger`. Each gives standard EasyAdmin CRUD screens (index/detail/
edit/new/delete) plus whatever its own
`configureFields()`/`configureActions()` customizes.
`NotificationLogCrudController`/`UserLedgerCrudController`/
`FriendlyChallengeCrudController` are read-only (disable new/edit/delete),
styled directly on `DeletionRequestCrudController`.

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

## Error shape on `/api/*`

Every error on an `/api` path is JSON regardless of the request's `Accept`
header — `ApiExceptionSubscriber` (see `services.md`) converts any uncaught
exception to RFC 9457 `application/problem+json` with `error` (stable machine
slug), `detail` and, for a failed request body, `violations[]`. Hand-written
error responses in controllers keep their existing
`{"error": ..., "message": ...}` shape; both carry `error`, so a client can
read that key for either. `/admin` keeps Symfony's HTML error pages.

**No `/api` route answers 2xx with an empty body** — every one returns via
`$this->json(...)`; there is no `204` or bare `Response` among them. Verified
by grep, and the client now treats an empty 2xx as a retryable transport
failure, so this is a property to preserve: use `204` if an empty-bodied
endpoint is ever wanted.

## Retry safety / idempotency

The `Idempotency-Key` header is honoured on POSTs under
`/api/competitions/`, `/api/friendlies/`, `/api/inbox/` and on the exact path
`/api/club/initialize` (`IdempotencySubscriber`). **Conclude-season is
deliberately not on that list** even though it is what the client's retry queue
was built for — it has a strictly better domain-level guarantee, so the header's
body-hash check could only ever fire as a false positive, and the client drops a
4xx. See that subscriber's docblock. Beyond the header:

| Route | Behaviour on retry |
|---|---|
| `POST /api/league/conclude-season` | Idempotent per club + season **for a retry**; replays the first response, writes nothing. A resubmission whose result figures *differ* is a **correction**: it overwrites `season_record` + `season_snapshot.snapshot_data`, bumps `overwrite_count` and audits the superseded position/W-D-L, while still replaying the first response and touching no club or derived state. Takes a new optional `season` in the payload — keying on the club's *current* season is unsafe for a late retry. Order-independent: a season *ahead* of the server's count is archived and pulls `currentSeason` up to it, deliberately **not** rejected — the client's outbox drops a 4xx, so a 422 there would silently lose that season and cascade into every one queued behind it |
| `POST /api/initialize/starter` | Replays the stored starter pack as `200` + `replayed: true`. Clubs predating `club.starter_pack_json` still get `409 starter_already_initialized` |
| `POST /api/initialize/league/{tier}` | Cache-backed and now race-safe; concurrent calls for one country+tier are serialized and share one build |
| `POST /api/club/initialize` | **Not** idempotent alone — creates an additional club. Does *not* collide on the club name (the duplicate check is against `NpcClub` only). Use an `Idempotency-Key` |

Client-facing detail: **Retries and idempotency** in
`docs/frontend-integration.md`; the investigation behind each row is the
2026-10-24 Sentry sweep, recorded in `shared/last-sync.md`.

**`POST /api/initialize/starter` accepts any `Country` case in `?country=` and
deliberately does NOT check `StarterConfig::enabledCountries`** — this
permissiveness is load-bearing, not an oversight. The client persists
`club.country` per save and re-reads it on every retry, so an `enabledCountries`
check here would brick every existing save in a country later removed from that
list: the init flow cannot proceed without a starter pack and there is no
client-side escape. (Which is also the hazard in `enabledCountries` itself —
it is one admin-editable row, and removing a country strands every save already
created in it.) If the goal is to stop *new* clubs in an unwarmed country, the
list the picker reads already does that; the fix for an existing one is to warm
it.

`POST /api/initialize/league/{tier}` logs every response as
`initialize.league_tier.served` (country, tier, cacheHit, durationMs, clubs,
players, staff, scouts, payloadBytes) — added so the client's
country-and-tier-less `db: slow write` warnings can be placed against a real
payload by timestamp.

## API spec

**No OpenAPI/Swagger spec file exists.** The only Swagger-named files
found are vendored **swagger-ui static assets** bundled by
`api-platform/core` under `public/bundles/apiplatform/swagger-ui/`
(display assets, not a project-authored spec). Since no entity is
`#[ApiResource]`-annotated, API Platform's auto-generated `/api/docs`
spec would in any case be effectively empty. Treat the routes documented
above as the sole source of truth — nothing to cross-check against.

## Location & land plots (added 2026-11-03)

| Method + path | Route name | Gate |
|---|---|---|
| `GET /api/plots/holds/mine` | `api_plot_holds_mine` | `IS_AUTHENTICATED_FULLY` |
| `POST /api/plots/{plotId}/hold` | `api_plot_hold_create` | `IS_AUTHENTICATED_FULLY` |
| `DELETE /api/plots/holds/{holdId}` | `api_plot_hold_release` | `IS_AUTHENTICATED_FULLY` |
| `GET /api/plots/claims/mine` | `api_plot_claims_mine` | `IS_AUTHENTICATED_FULLY` |
| `POST /api/plots/{plotId}/claim` | `api_plot_claim_create` | `IS_AUTHENTICATED_FULLY` |
| `POST /api/plots/claims/{claimId}/stadium` | `api_plot_claim_stadium` | `IS_AUTHENTICATED_FULLY` |

**`IS_AUTHENTICATED_FULLY`, deliberately NOT `ROLE_CLUB`.** Land can be bought before a
club exists, and `User::getRoles()` appends `ROLE_CLUB` unconditionally — so a
`ROLE_CLUB` gate here would *appear* to work in testing and break the day that is
corrected. That is the `/api/club/check` lockout, verbatim; see
`docs/api/role-club-403.md` and `01_overview/output/tribal-knowledge.md`.

`access_control` carries `- { path: ^/api/plots, roles: IS_AUTHENTICATED_FULLY }`, listed
**explicitly even though the `^/api` catch-all already grants exactly this**, so that a
future narrower rule (an admin-only `/api/plots/...`, say) has an anchor to be ordered
*ahead* of. First match wins, and that ordering is load-bearing.

Response codes, from `PlotUnavailableException`'s typed reasons:
`409 plot_held` / `hold_limit` / `plot_claimed` / `hold_consumed`,
`410 hold_expired`, `403 hold_not_yours`, `422 plot_not_sellable`,
and **`402 insufficient_credit`, which reports the LEDGER balance** — land and stadium
purchases are account credit, never `club.balance`.

`/holds/mine` and `/claims/mine` are `private` + `max-age=0`: a countdown is useless
cached and the answer is per-account.

### Admin routes

`admin_location_index` / `admin_land_plot_index` / `admin_country_profile_index` (EasyAdmin
CRUD), plus the custom `admin_location_enrichment` (GET) and three POST-only,
CSRF-guarded triggers: `admin_location_enrich_single`, `..._sweep`, `..._subset`.
**All three are POST.** The single-enrich action was a GET at first so an EasyAdmin row
action could link to it, and the attempt to make a money-spending GET safe with a token
in the query string does not work — Symfony's `SameOriginCsrfTokenManager` issues
double-submit tokens that cannot be validated from a URL parameter. The row action now
links to the enrichment screen, which carries the button.
