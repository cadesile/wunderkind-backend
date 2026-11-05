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
  `ArchetypeShowcaseService`, `LiveTelemetryService` — the telemetry aggregate it
  renders into the HTML is also served as JSON by `Api\TelemetryController`, so the
  snapshot shape now has two consumers.
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
- **`CountryController`** — single invokable action, no repository —
  maps `App\Enum\Country::cases()` directly to `{name, country, code}`
  rows. Public, cacheable. See `docs/api/countries.md`.
- **`TelemetryController`** — single invokable action over
  `LiveTelemetrySnapshotRepository` (NOT `LiveTelemetryService`: it only needs the
  cached singleton, never the aggregation). Public, cacheable, conditional-request
  aware (`ETag`/`Last-Modified` from `generatedAt`, 304 on `If-None-Match`) because
  the snapshot only changes every 15 min. Projects the snapshot rather than dumping
  it — `serializeFeedItem()` strips `at`, `time` and `dossier`; see
  `docs/api/live-telemetry.md` and `routes.md` for why each one must not ship.
- **`ArchetypeController`** — single read action over `PlayerArchetypeRepository`;
  `?lang=` localization via `LanguageRepository` + `NarrativeTranslationService`.
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
  `GET /check` is the other exception: it is the only action here that is
  **authentication-only** rather than `ROLE_CLUB`, because its 404 *is* the
  answer for an account with no club and a role gate made that branch
  unreachable for exactly those callers. It is also the only caller that
  catches `ClubMismatchException` instead of letting its 403 stand — a stale
  `X-Club-Id` is likewise an answer here, not a refusal (`reason:
  club_not_owned`). Both decisions are load-bearing and explained in
  `routes.md`; relaxing the attribute without the matching `access_control`
  entry changes nothing. See `docs/api/role-club-403.md`.
- **`CommunityStatsController`** — four read-only leaderboard views via
  `CommunityStatsService`.
- **`CompetitionController`** — competition discovery (`/available`, `/active`, filtered list `GET /api/competitions`, Hall of Fame `GET /api/competitions/champions`), bracket detail (`GET /api/competitions/{id}` with full future rounds, `viewer` block, `serverTime`, and `ETag` / `If-None-Match` 304), registration (`POST /{id}/register` with atomic entry fee deduction), withdrawal (`POST /{id}/withdraw` with entry fee refund while registering), and resubmission (`POST /{id}/resubmit` with stale `snapshotLockedAt` check). Deps: `ClubResolver`, `CompetitionTemplateRepository`,
  `ActiveCompetitionRepository`, `CompetitionEntrantRepository`,
  `CompetitionRoundRepository`, `CompetitionFixtureRepository`,
  `EligibilityEvaluator`, `SnapshotValidator`,
  `CompetitionRegistrationService`, `MatchQueryService`.
- **`ClubHubController`** — Tournament Hub combined read (`GET /api/club/hub`: `nextMatch`, `openCompetitions`, `myCompetitions`, `recentResults`, `unreadTournamentMessages`, `serverTime`) and club-entered competition history (`GET /api/club/competitions`). Deps: `ClubResolver`, `MatchQueryService`, `CompetitionTemplateRepository`, `ActiveCompetitionRepository`, `CompetitionEntrantRepository`, `CompetitionRoundRepository`, `CompetitionFixtureRepository`, `InboxMessageRepository`, `InboxService`, `EligibilityEvaluator`.
- **`MatchController`** — first-class match detail (`GET /api/matches/{matchId}` across both tournament fixtures and friendlies), club-scoped match list (`GET /api/club/matches?state=upcoming|recent` including `scheduled`, `awaiting_draw`, and `awaiting_entrants` states), and head-to-head summary (`GET /api/club/h2h/{opponentClubId}`). Deps: `ClubResolver`, `MatchQueryService`.
- **`FriendlyController`** — Friendlies Challenge Board (`GET /api/friendlies/challenges` with `?stake=` and `?reputation=` filters, `GET /api/club/friendlies/challenges`, `POST /api/friendlies/challenges`, `POST /api/friendlies/challenges/{challengeId}/cancel`, `POST /api/friendlies/challenges/{challengeId}/accept`). Deps: `ClubResolver`, `FriendlyChallengeService`.
- **`EventController`** — templates listing via
  `GameEventTemplateRepository`; `?lang=` localization via
  `LanguageRepository` + `NarrativeTranslationService`.
- **`ExcursionController`** — listing via `ExcursionRepository`; `?lang=`
  localization via `LanguageRepository` + `NarrativeTranslationService`.
- **`FinanceController`** — club finance overview/investors/sponsors,
  including sponsor termination. Deps: `ClubResolver`,
  `InvestorRepository`, `SponsorRepository`, and (per-method) other
  services as used.
- **`InboxController`** — inbox listing/accept/reject/read-marking/dismissal (`POST /api/inbox/{id}/dismiss`). Uses
  `ClubResolver`, `InboxMessageRepository`, `InboxService`. `GET /api/inbox` excludes `dismissed` items and emits `type`, `link`, and `requiresAccept`; `POST /api/inbox/{id}/accept` applies effects and returns `{ status: 'accepted', appliedEffects, club: { clubId, balance, reputation } }` (`409 already_processed` on repeat calls).
- **`LeaderboardController`** — `LeaderboardCalculationService`.
- **`LeagueController`** — season conclusion and history. Deps:
  `ClubResolver`, `LeagueService`, `SeasonSnapshotRepository`,
  `SeasonRecordRepository`.
- **`MarketController`** — market listing + assign/consume actions,
  including a legacy read path. Uses `ClubResolver`, `MarketDataService`,
  `MarketPoolService`, plus `AgentRepository`, `InvestorRepository`,
  `PlayerRepository`, `ScoutRepository`, `SponsorRepository`,
  `StaffRepository`.
- **`PlayerController`** — `GET /api/players/foreign`, random pool draw
  excluding one nationality (`PlayerRepository::findForeign()`). Shares
  `PlayerBrowseSerializer` with `ScoutSearchController::search()` so both
  endpoints emit byte-identical player objects.
- **`PoolController`** — idempotently tops up the unassigned-player pool
  for a nationality via `MarketPoolService` + `PlayerRepository`.
- **`ScoutSearchController`** — foreign-club listing (`NpcClubRepository`)
  and player search (`PlayerRepository`). Player serialization moved to
  `App\Service\PlayerBrowseSerializer` (was a private method here) when
  `PlayerController` needed the same shape.
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
  quick-edit actions. `LeagueAdminController` also has
  `bulkEditTierDefaults()` (League Tier Defaults screen), using
  `GameConfigRepository`/`LeagueRepository` alongside `EntityManagerInterface`.
- **`StarterPackCheckController`** — renders
  `StarterPackDiagnosticService`'s result for one country/tier. No writes,
  no entity consumption; the same result object the
  `app:starter-pack:check` command prints.
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

37 EasyAdmin CRUD controllers, one per entity (full list in
`routes.md`). Each provides standard index/detail/edit/new/delete screens
via `configureFields()`/`configureActions()` — individual field
configuration wasn't traced controller-by-controller (not architecturally
significant beyond the pattern itself).

**`NotificationLogCrudController`** — read-only (new/edit/delete
disabled), styled directly on `DeletionRequestCrudController`: a
`ChoiceField` status badge + `ChoiceFilter`, `TextFilter` on
`messageType`/`summary`/`errorMessage`, and a `CodeEditorField` JSON
detail view (`detailJsonPretty`) shown only on the detail page.

**`FriendlyChallengeCrudController`** — read-only (new/edit/delete
disabled), the admin view of the friendly challenge board under
Competitions -> Challenges. Status badge + `ChoiceFilter`, `EntityFilter` on
both clubs, and a virtual score column; the detail page pretty-prints all
eight `json` columns (both squad snapshots, both result club blobs, both
lineups, the event log and the narrative). **Those json fields are virtual
`TextField`s with a placeholder `setValue('')`, not fields bound to the real
array-valued properties** — measured, not assumed: `TextField` bound to an
array-valued `json` property is rejected by its configurator ("can't be
converted into a string"), and a generic `Field::new()` silently renders the
literal text "Array" and never calls `formatValue()` at all. The formatter
escapes the JSON before wrapping it in `<pre>`, because `TextField` renders
`field.formattedValue|raw` and these blobs carry client-supplied squad,
lineup and narrative data. Lifecycle stays with `FriendlyChallengeService`
(posting, accepting, the `resolveLockedAt` claim-lock, expiry) — hence no
edit path. Covered by `FriendlyChallengeCrudPageTest`, which loads both an
OPEN row (null acceptor, null scores, all result blobs null) and a COMPLETE
one.

**`LeaderboardAwardDefinitionCrudController`** — **full CRUD**, the thing operators
actually configure: which chart awards, at what cadence, to how many places. `category`
and `cadence` use `ChoiceField` + `EnumType` rather than a string-valued choice list —
the entity properties hold enum instances, and strings make the form page 500 with
"Object of class … could not be converted to string" (a real failure here, not a
precaution). Placements are a `CollectionField` of `LeaderboardAwardPlacementType`, which
is deliberately a child entity + plain `FormType` rather than a json column with
`setFormType()` — the latter hits the documented injected-`CollectionType`-options trap.
`lastClosedPeriodKey`/`lastClosedAt` are `->hideOnForm()`: an editable monotonic anchor
re-opens closed periods when moved back and silently skips them when moved forward, and
`LeaderboardAwardAdminTest` asserts they are absent from the form.
`persistEntity()`/`updateEntity()` refuse a duplicate `(category, cadence)` with a flash
**before** flushing, because letting the unique throw out of `flush()` closes the
EntityManager. Its detail page is overridden
(`admin/leaderboard_award_definition_detail.html.twig`, overriding
`content_header_wrapper` with `{{ parent() }}` first) to add a **Close now** panel —
there is no crontab under Lando, so this is how the close job is exercised by hand. That
panel posts to `LeaderboardAwardCloseController` (`admin_leaderboard_award_close_now`),
**POST-only and CSRF-guarded**, with a "close the current, still-open period early"
checkbox for the `--force` path; closing a period prunes live rows, pays prizes and sends
inbox mail, so a stray GET returns 405 rather than doing any of that.

**`LeaderboardPeriodArchiveCrudController`** — read-only (new/edit/delete disabled),
sorted `closedAt DESC`. An archive is immutable history written by the close job. A row
sitting at `pending` past its 300s claim lease is the operational signal that a close died
part-way — its archive is incomplete and its live `leaderboard_entry` rows are
deliberately unpruned.

**`LeaderboardPeriodArchiveEntryCrudController`** — read-only, and **this is the "awards
won by this account" screen**: filterable on `userId` and `awarded`, which is exactly why
those are a denormalised indexed string and a plain boolean rather than an association.
Account erasure nulls `user_id` and keeps the row, so this screen stops returning rows for
a deleted account — correct, and documented in `docs/api/account-delete.md`.

**`UserLedgerCrudController`** — read-only (new/edit/delete disabled), same
`NotificationLogCrudController`/`DeletionRequestCrudController` pattern — an
audit trail of `UserLedger` rows, filterable by `user`/`club`
(`EntityFilter`) and `type` (`ChoiceFilter`), with
`balanceBeforePence`/`balanceAfterPence` shown so a mismatch between adjacent
rows for the same user is visibly a bug.

It also carries the **one place a human writes a ledger row**: a global
`New Adjustment` action linking to
`GET|POST /admin/user-ledger-entries/create-adjustment`
(`admin_user_ledger_create_adjustment`), which records a `custom_income` entry
of either sign against an account. Four things about it are load-bearing:

- **EasyAdmin's `NEW` stays disabled and the row is still written by
  `UserLedgerService::append()`.** `UserLedger`'s constructor is private and
  `balanceAfterPence` is computed inside it with no setter, so a CRUD form
  cannot build one — and would take the balance from form input, bypassing the
  per-user advisory lock `append()` holds while reading the real balance. The
  form posts three fields (user, amount, description); the service does the
  rest. There is a test asserting `/new` and `/edit` still answer 403.
- **The path segment is `user-ledger-entries`, not `user-ledger`**, because
  EasyAdmin's detail route is `/admin/user-ledger/{entityId}` with an
  unconstrained wildcard and would shadow it — the same trap
  `CompetitionEntrantCrudController` documents.
- **The uniqueness token is minted when the form is rendered**, not per
  request, and rides in `sourceRef` as `<adminUuid>:<26 hex>` (63 of the
  column's 64 chars, keeping the admin id whole so attribution is a
  `LIKE '<adminId>:%'`). Resubmitting one rendered form therefore hits
  `append()`'s idempotency guard and writes nothing rather than crediting
  twice; the replay is flashed as a **warning naming the recorded amount**,
  never as success.
- **It clears `OwnerSpotlightService`**, because for any account that has drawn
  a dividend this row becomes their newest and therefore their figure on the
  public Rich List (`findTopOwnersByBalance()` publishes the latest row's
  balance for any type).

The balance preview needs no endpoint: `UserLedgerRepository::findBalancesByUser()`
(one `DISTINCT ON (user_id)` pass) renders every user's balance onto its
`<option>`, and `public/assets/user-ledger-adjustment.js` does the arithmetic.
The two balance inputs carry **no `name`**, so a browser cannot post them.

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

**`TranslationKeyCrudController`** (generic UI-copy keys only —
narrative-linked keys are excluded via `createIndexQueryBuilder()`'s
`entityType IS NULL` filter and managed only via `NarrativeTranslationController`):
- Index is grouped by key with one green/red presence-icon column per
  *enabled* `Language`, instead of EasyAdmin's default one-row-per-entity
  grid — since this entity already is "one row per key," a virtual field
  per language (`translated_<code>`, computed in `formatValue()` from one
  bulk-queried `TranslationRepository::findGenericTranslationStatusMap()`
  call, not a real column) gets the grouped view "for free." Uses
  `TextField`, not `BooleanField` — see the EasyAdmin gotcha in CLAUDE.md
  ("virtual/computed index columns ... `BooleanField`'s own template
  reads `field.value` directly").
- `configureFilters()` adds `App\Filter\IncompleteTranslationFilter` (see
  `structure.md`'s `Filter/` entry) — a toggle narrowing the index to keys
  missing at least one enabled language's translation.
- `edit()` overrides `configureCrud()`'s `crud/edit` template
  (`admin/translation_key_edit.html.twig`, extending `@EasyAdmin/crud/edit.html.twig`
  and overriding `main` below the real form, same "override one block"
  technique `UserCrudController::edit()` uses on `content_header_wrapper`
  above it) to inject a per-enabled-language textarea + Save button grid.
  Each language saves independently over AJAX against `saveLanguage()`
  (`POST /admin/translation-key/{id}/save-language/{code}`) rather than
  through the page's own form; a blank submitted value clears that
  language's `Translation` row (falls back to the default language)
  rather than storing an empty override, same convention as
  `NarrativeTranslationService::saveTranslations()`.

One exception worth noting: **`AdminMessageCrudController`** overrides
`persistEntity()`/`updateEntity()` to dispatch
`ResolveAdminMessageAudienceForPushMessage` via Messenger the first time
a message is saved Active with `sendAsPush` checked (`pushSentAt` guards
against resending on later edits) — see `services.md`'s
`Notification/` entries.

## Location & land plots (added 2026-11-03)

**`Api\PlotHoldController`** / **`Api\PlotClaimController`** — the six player-facing
endpoints (see `routes.md`). Both gate on `IS_AUTHENTICATED_FULLY`, **not `ROLE_CLUB`**,
because land can be bought before a club exists and `User::getRoles()` appends
`ROLE_CLUB` unconditionally. Both map `PlotUnavailableException`'s typed reasons to
distinct status codes rather than returning one generic 409, because the cases mean
different things to a client: `plot_held` is transient and worth retrying, `hold_limit`
is the player's own doing, `plot_not_sellable` is permanent, and `insufficient_credit`
carries the balance and the requirement so the client can render the shortfall.

**`Admin\LocationCrudController`** — full CRUD. Two behaviours that are not obvious from
the fields: **editing a coordinate, population or land value LOCKS that field** against
future AI enrichment (compared against the DATABASE row, since the form has already
written onto the entity by the time `updateEntity()` runs, with a micro-degree tolerance
so a plain re-save does not lock anything); and **deleting a location with plots is
refused with a flash**, because the FK is ON DELETE RESTRICT and the alternative is a
`ForeignKeyConstraintViolationException` reaching the operator as a 500. `citySize` is
shown read-only — it is derived from the whole country's population spread.

**`Admin\CountryProfileCrudController`** — the readiness gate. Setting status to LIVE is
refused with a flash listing the failing checks unless `CountryReadinessService` passes,
enforced in `persistEntity()`/`updateEntity()` rather than with a DB constraint — the
`LanguageCrudController` single-default precedent, for the same reason (low-frequency
single-admin CRUD, not a concurrency-sensitive path).

**`Admin\LandPlotCrudController`** — assigns `plot_number` itself from
`nextPlotNumber()`, so `UNIQUE(location, plot_number)` cannot throw out of `flush()`,
and refuses to delete a location's last plot.

**`Admin\LocationEnrichmentController`** + `templates/admin/location_enrichment.html.twig`
— the overview (per-country progress, the needs-review work queue, recent runs, 30-day
token spend) and the three triggers. Modelled on the `LeaderboardAwardCloseController`
"Close now" panel, because there is no crontab under Lando and an admin-triggered POST is
how a queued job gets exercised by hand. **All three triggers are POST-only and
CSRF-guarded**, because each queues billed API calls and a crawler or browser prefetch
must not be able to spend money. Redirects go through
`$this->redirect($this->generateUrl('admin', ['routeName' => ...]))` per
`src/Controller/Admin/CLAUDE.md`.

Two EasyAdmin traps hit while building these, both already documented elsewhere in this
repo and both confirmed the hard way:
- **An enum-typed property needs `ChoiceField` + `EnumType`**, not `setChoices()` with
  string values — the latter 500s the form with "Object of class … could not be converted
  to string". Six fields across the three controllers.
- **Custom routes rendering an `@EasyAdmin`-extending template must be *reached* via
  `/admin?routeName=...`.** EasyAdmin *forwards* rather than redirects, so `routeParams`
  arrive as request attributes and never appear in `$request->query` — the enrichment
  screen resolves its focused location from both.

**`Api\ClubController::nameOptions()`** gained an explicit three-step fallback chain.
Curated town names moved to the database, which silently gave that endpoint a new
dependency: with an unseeded `location` table the England fallback produced nothing and
`?country=XX` returned an **empty city list**, which is a dead end at club creation
rather than degraded output. It now falls back country → England → placeholder names, and
`testNameOptionsNeverReturnsAnEmptyCityList` guards it.
