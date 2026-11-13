# Domain Entities & Relationships

All facts below trace to `src/Entity/*.php` files opened during this pass.
Doctrine entity attributes are the authoritative current schema —
migrations are the change log (see `migrations.md`).

## Auth / user

- **`User`** — auth identity (`UserInterface`). `email`, `password`,
  `roles:array`, `isVerified`, `verifiedAt`, `lastLoginAt`, `createdAt`.
  **`roles` is not where `ROLE_CLUB` comes from.** `getRoles()` appends it
  unconditionally (`Admin::getRoles()` hardcodes `ROLE_ADMIN` the same way), because
  the column has twice held a value that granted nothing — `[]` before `6dc85ab`
  (2026-03), the since-deleted `ROLE_ACADEMY` until `2a9e6cd` (2026-04) — and with no
  `role_hierarchy` either shape 403'd every `roles: ROLE_CLUB` route for an account
  that had logged in fine (`isVerified()` is the login gate, not roles). A `User` row
  *is* a game account; admins are a separate entity behind a separate firewall, so the
  stored array never carried information that could withdraw club access. The
  guarantee is additive — anything else stored is kept — and the consequence is that
  club access can no longer be withdrawn by emptying the column; a ban would want its
  own flag. Deploying fixes every stale row with no backfill, which is why none was
  run: an `UPDATE` would only hide how the data got that way. See
  `04_interfaces/output/routes.md` and `docs/api/role-club-403.md`.
  Owner identity (replaces the old `managerProfile:?array` blob):
  `name:?string`, `nationality:?string`, `gender:?string`, `dob:?date`,
  `appearance:?array` (json, 15-key sprite shape — see
  `04_interfaces/output/services.md`'s Avatar Appearance /
  `OwnerAvatarController` entries). `OneToMany` → `clubs:Collection<Club>`
  (cascade persist/remove); no uniqueness constraint on the FK. A `User` has
  no direct relation to `UserLedger` (see below) — it's queried, not
  traversed, via `UserLedgerRepository`.
  `tokenVersion:int` (default 0) is the **access-token kill switch**: the `api`
  firewall is stateless JWT with no denylist, so a token cannot be withdrawn
  once issued. Every token carries this number as a `tv` claim
  (`JwtTokenVersionSubscriber`, on `JWT_CREATED`), and the same subscriber
  rejects any token on `JWT_DECODED` whose claim no longer matches the column.
  Bumping it therefore signs the account out everywhere immediately. An
  **absent** `tv` claim reads as 0 — the same value the migration's DEFAULT
  gives every existing row — so shipping it signed nobody out, while a killed
  account's pre-existing tokens still stop working. Written only via
  `SessionRevocationService`, never directly.
  `debugLoggingEnabled:bool` (default false) is a **per-account override for the
  in-app debug log panel**, so one player's device can be debugged without
  exposing the panel (it logs every API request and response) to the whole
  player base. It is **additive to** the global `GameConfig::$debugLoggingEnabled`,
  never a replacement: `DebugLoggingResolver::resolve()` (the single definition)
  serves `global || per-user` under the unchanged `debugLoggingEnabled` key, so
  this flag can only ever switch the panel ON while the global switch is off,
  and can never switch it OFF for an account the global switch covers.
  **Two endpoints carry it:** `GET /api/game-config` (PUBLIC_ACCESS, so the
  per-user half only applies when the client actually sends its Bearer token —
  an anonymous cold boot gets the global value alone) and `GET /api/club/status`
  (`ROLE_CLUB`, so the override always applies — the reliable read for a
  signed-in client, and the reason the mirror exists). Client contract:
  `docs/api/debug-logging.md`.
- **`UserLedger`** (table `user_ledger`) — centralized, cross-club financial
  audit trail for one `User`. **UPDATED 2026-11-03** (`Version20261103180000`,
  `Version20261103230000`): six new `UserLedgerEntryType` cases (`STARTING_GRANT`,
  `QUIZ_BONUS`, `LAND_PURCHASE`, `STADIUM_PURCHASE`, `SELL_BACK_CREDIT`, `REVERSAL`);
  **`club_id` is now NULLABLE with ON DELETE SET NULL**; new `sourceKind`/`sourceRef`
  with a partial unique `uq_user_ledger_backend_source (source_kind, source_ref, type)
  WHERE both NOT NULL`; and the three money columns are **BIGINT, not integer**.
  Four things a reader must know:
  **(1) `getTotalDividendsByClub()` and `sumDividendsByClub()` now FILTER BY TYPE.**
  The latter feeds the PUBLIC `CAREER_EARNINGS` leaderboard and was an unfiltered
  `SUM(amount_pence)`; purchases are stored negative, so the first one would have
  deflated a club's public score and a club that spent more than it drew would have gone
  negative on an unsigned column.
  **(2) `findTopOwnersByBalance()` (the landing-page Rich List) now requires a real
  dividend draw** — otherwise `STARTING_GRANT` would put every account on it.
  **(3) Nulling `club_id` fixed a money bug.** While it was NOT NULL with a plain FK,
  both delete paths had to DELETE a club's rows; because the balance is a chain of
  before/after snapshots read from the latest surviving row, deleting a club rolled its
  owner's balance back. `UserLedger` was therefore REMOVED from
  `AccountDeletionService::BLOCKING_CLUB_DEPENDENTS` and from `DeleteAdminController`'s
  delete loop — the FK detaches the rows itself.
  **(4) BIGINT is required, not future-proofing.** The old 4-byte columns capped at
  £21,474,836, below a single Birmingham stadium (~£69M).
  The constructor is now **private**, with `fromSyncLedgerEntry()` and
  `forBackendEvent()`; `UserLedgerService::append()` is the single writer for
  backend-originated entries and takes a **per-user advisory lock**, because
  `getCurrentBalance()` is an unlocked read and two concurrent writers would fork the
  chain. Originally only dividend draws the client
  reports via the sync payload's free-form `ledger[]` array (category
  `dividend_draw`) — see `04_interfaces/output/services.md`'s
  `UserLedgerService` entry. `type:UserLedgerEntryType(enum, only
  DIVIDEND_DRAW exists)`, `amountPence:int` (signed; positive credits the
  user's overall balance), `balanceBeforePence/balanceAfterPence:int` — a
  snapshot of the user's running cross-club balance immediately
  before/after this row, so the total is reconstructible from history alone
  (the latest row's `balanceAfterPence` is the current balance —
  `UserLedgerRepository::getCurrentBalance()`). `occurredAt` (in-game date,
  the originating sync's `clientTimestamp`) vs `recordedAt` (real-world
  date, the originating sync's `serverTimestamp`) — same
  client-time-vs-server-time split as `Transfer`'s `occurredAt`/`syncedAt`.
  `ManyToOne` → `user:User` (not nullable, `CASCADE`), `club:Club` (not
  nullable — the club whose sync reported the draw), `sourceSyncRecord:
  ?SyncRecord` (nullable, `SET NULL` — **not** `CASCADE`: a rollback purges
  superseded `SyncRecord` rows via
  `SyncRecordRepository::deleteByClubFromWeek()`, but a draw already folded
  into a user's balance must survive that purge, same reasoning as
  `PlayerCareerStatSnapshot::$syncRecord`). `sourceLedgerIndex:?int` — the
  entry's index within that `SyncRecord`'s `payload['ledger']` array;
  `UNIQUE(sourceSyncRecord, sourceLedgerIndex)` is what makes re-running
  `app:backfill-user-ledger` (or re-processing a resent sync) idempotent.
  No rollback-purge special-casing beyond the FK's `SET NULL` — dividend
  draws recorded against a rolled-back timeline are not retroactively
  reversed, same precedent as `Transfer`/`PlayerCareerStatSnapshot`.
  **`club` is a club-delete blocker and both delete paths must purge it
  explicitly.** It is `NOT NULL` with a plain NO-ACTION FK, so unlike
  `transfer.club_id` it cannot be nulled and unlike `inbox_message` it is not
  cascaded; `user_id`'s own `CASCADE` fires only when the *user* row goes,
  which is after its clubs. Shipping the entity without adding it to
  `AccountDeletionService::BLOCKING_CLUB_DEPENDENTS` and
  `DeleteAdminController::cleanClubForeignKeys()` left both deletes raising
  `ForeignKeyConstraintViolationException` on `fk_user_ledger_club` in
  production. Deleting a club therefore discards that club's dividend rows —
  unambiguous when the account goes too, but note that the admin's
  delete-club-keep-user path drops rows out of a chained balance history, and
  `getCurrentBalance()` reads the latest surviving row, so removing the most
  recent entries rolls the user's balance back to an earlier figure.
- **`Admin`** — separate back-office auth identity (`UserInterface`).
  `email`, `password`, `name`, `department`, `accessLevel:int(1)`.
  `getRoles()` hardcodes `['ROLE_ADMIN']`. No entity relations.
- **`Guardian`** — a player's guardian. `firstName/lastName`, `gender`,
  `dateOfBirth`, `contactEmail`, `demandLevel:int(5)`,
  `loyaltyToClub:int(50)`. `ManyToOne` → `player:Player` (not nullable).
- **`EmailVerification`** — verification codes. `code`, `expiresAt`,
  `purpose:VerificationPurpose(enum)`, `attempts`, `verifiedAt`.
  `ManyToOne` → `User` (not nullable, `onDelete:CASCADE`).
- **`RefreshToken`** — extends `BaseRefreshToken` from
  `gesdinet/jwt-refresh-token-bundle`, table `refresh_tokens`.
- **`BetaRequest`** — beta signup gate. `email`, `code`, `valid:bool`,
  `attempts`, `expiresAt/createdAt`, `verifiedAt/invitedAt`.
- **`DeletionRequest`** — GDPR deletion tracking. `email`,
  `status:DeletionRequestStatus(enum)`, `ipAddress`, `failureReason`,
  `clubsDeleted:int`, `requestedAt/completedAt`.
- **`UserDevice`** (table `user_device`) — an FCM registration token
  for one app installation (push notifications). `deviceToken`
  (**unique across the table, not per-user** — a token identifies an
  installation, not an account; re-registering an existing token under
  a different user reassigns it), `platform:DevicePlatform(enum: ios,
  android)`, `deviceId:?string` (client-generated, unused so far),
  `lastActiveAt/createdAt`. `ManyToOne` → `User` (not nullable,
  `CASCADE`). Registered via `POST /api/device-tokens`, sent to by
  `PushNotificationService` — see `services.md`.
- **`NotificationLog`** (table `notification_log`) — audit record of one
  push-related Messenger message actually processed (success or failure),
  one row per `SendPushNotificationMessage`/
  `ResolveAdminMessageAudienceForPushMessage` handled. `status:NotificationLogStatus(enum:
  SUCCESS, FAILED)`, `messageType:string` (short class name), `summary`,
  `detailJson:array` (message properties + exception detail on failure),
  `errorMessage:?string` (truncated to 255, same convention as
  `DeletionRequest::$failureReason`), `createdAt`. No entity relations —
  written by `NotificationLoggingSubscriber` (see `services.md`), not by
  the handlers themselves, since that's the only place that uniformly
  catches both a handler's own exception and a handler-*construction*
  failure (the exact bug class — a missing `FIREBASE_SERVICE_ACCOUNT_JSON`
  — that motivated this table).

## Core game / club

- **`Club`** — the player's club, central aggregate. `name`, `reputation`,
  `totalCareerEarnings` (**deprecated** — a client-supplied accumulator of every
  income the club ever had; no longer backs any board or admin panel, superseded
  by `UserLedger` dividend draws. Still written on sync and still emitted by
  `GET /api/club/status`, because the shipped client reads it — removal needs a
  coordinated wunderkind-app release. `EconomicService::processFinancialYearEnd()`'s
  server-computed manager dividend still lands here and deliberately writes no
  ledger row, so this column is its only record), `hallOfFamePoints`, `lastSyncedWeek`,
  `lastSyncedAt`, `reputationDecayedAt:?datetime` (added by
  `Version20261012215721`) — how far dormancy-based reputation decay has been
  charged; exists purely to make `app:reputation:decay` idempotent and
  catch-up-safe, see `docs/reputation-decay.md`, `marketPoolSize:int(20)`, `financialYearStart:int(4)`,
  `country`, `abbreviation`, `worldInitializedAt/starterInitializedAt/
  tutorialCompletedAt`, `starterPackJson:?array` (added by
  `Version20261024140000`) — the `ampStarter` payload `POST /api/initialize/
  starter` first answered with, and **the only copy that exists**: starter
  players/staff are pool entities, deleted on consume, so before this a lost
  response left the club initialized with no squad and a permanent 409 on
  every retry. Null for clubs initialized before it shipped; those still 409. `paName`, `managerTemperament/managerDiscipline/
  managerAmbition:int(50)`, `balance:int` (the old `managerProfile:?array`
  blob is gone — the owning `User`'s own name/nationality/gender/dob is
  the single owner identity now, see Owner Identity in CLAUDE.md),
  `currentSeason:int(1)`, `formation:Formation(enum, F_442)`,
  `fanCount/fanSentiment/fanMorale`, `lastWeeklyAttendance/
  totalSeasonAttendance`, `isSpoof:bool(false)` (added by migration
  `Version20260918205514`), `homeKitConfig/awayKitConfig/badgeConfig:?array`
  (added by `Version20260928200000`) — the chairman's own kit+badge identity,
  same shape as `NpcClub.identity` but three separate nullable properties;
  client-supplied verbatim via `GET`/`POST /api/club/kit-identity`, no
  server-side validation, `null` until customized (no auto-generation, unlike
  `NpcClub.identity`) — see `docs/api/club-kit-identity.md`. Relationships: `ManyToOne` → `User` (not
  nullable); `OneToMany` → `transfers`, `syncRecords` (cascade
  persist/remove), `leaderboardEntries` (cascade persist/remove),
  `investors`, `sponsors`, `inboxMessages` (cascade persist/remove);
  `ManyToOne` → `currentLeague:?League` (nullable).

  **`tutorialCompletedAt` means new-game SETUP completed, not a tutorial** (corrected
  2026-11-02; this entry previously listed the column among the field names with no
  meaning attached, and the entity docblock said "first-launch tutorial"). It is written
  set-once from `SyncRequest` and read by the `setupCompleted` audience criterion
  (`AudienceCriteriaEvaluator`; that key was itself called `tutorialCompleted` until
  2026-11-02, renamed with `Version20261102120000` plus a legacy alias — **the column keeps
  the old name on purpose**, since it is an API field the client sends and two endpoints
  echo). The walkthrough that originally wrote it was deleted
  client-side on 2026-09-29, taking the only writer with it, so the column was null — and
  the criterion silently false — for every club created between then and 2026-11-02, while
  `tutorialCompleted: true` remained the worked example in the audience admin's own help
  text (both fixed 2026-11-02). **A one-off backfill is still owed** for the clubs in that gap: `lastSyncedWeek >= 1`
  is the sound test, since it only advances through a sync and a sync cannot happen until
  setup has completed. The column is not vacuous despite every club having finished setup
  to exist as a *save* — the row is created at FOUND CLUB, with eleven setup steps still to
  go, so an abandoned setup leaves a club row behind and that is what this distinguishes.
  See `docs/api/setup-tutorial.md`.
  > **The device owns this row's game state; the backend only mirrors it.**
  > Everything per-club reaches the backend through `POST /api/sync` and is
  > overwritten absolutely from the device's own value — `balance`,
  > `totalCareerEarnings` and `reputation` are assigned, not accumulated
  > (`SyncService::process()`: *"The client is the game engine… accepted as
  > authoritative snapshots from the device"*). So a server-side write to any of
  > them lasts only until the next sync, and the backend has no authority to
  > correct a club's state — a wrong value has to be fixed on the device. Do not
  > read a column here as something the backend owns just because it is stored
  > here; see `01_overview/output/tribal-knowledge.md` for the mistake this
  > caused once already.
- **`ClubFacility`** — a club's built facility instance. `facilitySlug`,
  `level`, `updatedAt`. `ManyToOne` → `Club` (not nullable, `CASCADE`).
- **`FacilityTemplate`** — facility catalog (not a relation target of
  `ClubFacility` by FK — joined via `facilitySlug`). `slug/label/
  description`, `category`, `baseCost`, `weeklyUpkeepBase`,
  `matchdayIncome/Multiplier`, `reputationBonus`, `maxLevel:int(5)`,
  `decayBase`, `gameplayEffects:array`, `baseConstructionWeeks:int(4)`,
  `sortOrder`, `isActive`, `imagePath`.
- **`League`** — a competitive tier. `country`, `tier`, `name`,
  `promotionSpots`, `tvDeal`, `leagueReputationTier:?ReputationTier(enum)`,
  `prizeMoney`, `leaguePositionPot`, `sponsorCount`,
  `trophy:?array` (json, nullable — the 16-bit trophy sprite's own
  `TrophyConfig`: `{style, finish, ribbons, ribbonPrimary, ribbonSecondary}`,
  stored and served verbatim like `Player::$appearance`. Replaced the retired
  `trophyImage` slug + `trophyColour:?TrophyColour(enum)` pair in
  `Version20261018202041`; null means "awards no trophy" and is load-bearing —
  see CLAUDE.md's "Trophy Identity" section and `docs/api/trophy-config.md`).
  `OneToMany` → `leagueSponsors`
  (cascade persist/remove, orphanRemoval); `ManyToMany` → `sponsors`
  (explicit `JoinTable`, `onDelete:CASCADE`). At creation,
  `LeagueService::generateLeaguesForCountry()` populates these financial/
  trophy/sponsor fields from `GameConfig::leagueTierDefaults` (tier-only,
  shared across every country, admin-editable) — see that entity below and
  CLAUDE.md's "World Generation" section.
- **`LeagueSponsor`** — league↔sponsor income join (table
  `league_sponsor_income`). `rolledValue:int`. `ManyToOne` → `League`,
  `Sponsor` (both `CASCADE`).
- **`NpcClub`** — AI-controlled club. `name/country`, `tier/reputation`,
  `primaryColor/secondaryColor` (synced from `identity.home`, not
  independently admin-editable), `abbreviation`, `stadiumName`, `balance`,
  `playingStyle('DIRECT')`, `financialApproach('BALANCED')`,
  `managerTemperament:int(50)`, `facilities:array`, `region`,
  `citySize:CitySize(enum)`, `populationSize`, `isCapital`,
  `formation:Formation(enum)`. `identity:array|null` (json) — nested
  `{home: KitVariant, away: KitVariant, badgeShape, badgePattern,
  badgeCentre, initials, badgeFill, badgeTrim, badgeSymbol}`, where a
  `KitVariant` is `{kit, primary, secondary, shorts, socks}` (shared enums
  with the player sprite's `kit`/`primary`/`secondary`/`shorts`/`socks`) —
  see CLAUDE.md's "Kit & Badge Identity". `ManyToOne` → `League` (nullable).
- **`Transfer`** — a transfer event record. `playerName/playerPosition/
  clubLeaving`, `destinationClubName`, `type:TransferType(enum)`,
  `fee/agentCommission/netProceeds/developmentPoints/reputationGained`,
  `buyingClub`, `occurredAt/syncedAt`. `ManyToOne` → `Player` (nullable,
  `SET NULL`), `Club` (nullable, `SET NULL`).
- **`SyncRecord`** — one client sync event. `clientWeekNumber`,
  `clientTimestamp/serverTimestamp`, `payload:array`, `debugLog:?array`,
  `isValid`, `invalidReason`, `isRollback`. `ManyToOne` → `Club` (not
  nullable). **`payload.ledger[].amount` is 100x true pence — see
  `schema.md`'s "`sync_record.payload` field conventions" section before
  reading or displaying it anywhere.**
- **`SeasonRecord`** — end-of-season league result. `season/
  finalPosition/gamesPlayed/wins/draws/losses/goalsFor/goalsAgainst/
  points:int`, `promoted/relegated:bool`, **`overwriteCount:int`** (default 0)
  and **`overwriteAudit:?array`** (json, both added by `Version20261101120000`).
  `ManyToOne` → `Club`, `League` (both not nullable). **`UNIQUE(club, season)`**
  (`uq_season_record_club_season`, added by `Version20261024132000`) — one
  concluded season per club, enforced in the DB because `POST /api/league/
  conclude-season` is client-retried and `hallOfFamePoints` is *summed* from
  these rows, so a duplicate double-counted a title permanently.

  **That constraint bounds the number of rows, not the number of writes.** A
  resubmission for an already-concluded season is a *retry* if its result figures
  match and a *correction* if they differ: a correction rewrites this row in
  place, bumps `overwriteCount` and appends the superseded position and W/D/L to
  `overwriteAudit` (`{overwrittenAt, finalPosition, wins, draws, losses}`,
  oldest first, null until first corrected, deliberately uncapped). The
  comparison is `SeasonRecord::matchesResult()` over ten fields only —
  `SeasonSnapshot`'s bulk blobs are excluded, because the retry queue resends a
  52KB `seasonFixtures` array and a reordering of it is a retry. `overwriteCount`
  is the authoritative total if it and the audit ever disagree.

  A correction rewrites this row and `season_snapshot.snapshot_data`, and
  **nothing else** — not the club's league or `currentSeason`, not sponsors, not
  fixtures, not the derived `Club::$hallOfFamePoints`. See
  `04_interfaces/output/services.md`'s `LeagueService` entry.
- **`SeasonSnapshot`** — season point-in-time snapshot. `season`,
  `country`, `snapshotData:array` (rewritten when a resubmission corrects the
  season — see `SeasonRecord` above), `responseJson:?array` (added by
  `Version20261024132000`) — the exact conclude-season response body, replayed
  verbatim to a duplicate or late retry rather than recomputed (concluding
  re-rolls sponsors and regenerates fixtures, so a recomputed reply would
  describe a different world). `ManyToOne` → `Club` (not nullable).
  **`UNIQUE(club, season)`** (`uq_season_snapshot_club_season`, same
  migration). See Idempotent Conclude-Season in `services.md`.
- **`SeasonRatingsSnapshot`** — denormalized ratings snapshot; stores
  `clubId`/`clubName` as plain strings, no FK relation. `season/weekNum/
  tier`, `overallRating`, `expectedPosition`.
- **`MatchResult`** — a played match's result. `goalsFor/goalsAgainst/
  week/season`, `fixtureId`, `opponentClubName`, `isHome`, `homeGoals/
  awayGoals`, `round`, `playedAt`, `yellowCards/redCards`. `ManyToOne` →
  `Club` (not nullable).
- **`LeaderboardEntry`** — one club's score in one leaderboard category.
  `category:LeaderboardCategory(enum)`, `score`, `period` (`'all-time'`, or a period key
  from `LeaderboardPeriod` — `2026-W44` / `2026-M10` / `2026-Q4`), `rank` (column
  `rank_position`, since `rank` is reserved SQL), `displayLabel`,
  **`baselineScore:?int`** and **`cumulativeScore:?int`** (both `bigint`, nullable).
  `ManyToOne` → `Club` (not nullable).

  **`'all-time'` is a standing; every other period is a GROWTH board**, and the two columns
  above are the whole mechanism. `score` has never been an accumulator — every writer
  re-reads a cumulative total and stores it absolutely — so deleting rows at a period
  boundary resets nothing. Only a baseline does.
  `LeaderboardEntry::observeCumulative()` is the single writer for a period row: it stamps
  `baselineScore` on first sighting, records the truthful `cumulativeScore`, and stores
  `score = max(0, cumulative - baseline)`. `setScore()` is for `'all-time'` only, and leaves
  both new columns NULL.

  Three numbers, not one, and **only the cumulative chains between periods**, so growth can
  never compound its own clamping. The `max(0, …)` is a product rule, not a safety net:
  `score` is `bigint unsigned` and growth genuinely goes negative (`club_reputation` decays
  daily), and keeping `cumulativeScore` separate is exactly what stops the clamp corrupting
  the next period's baseline.

  The baseline lives **here**, on the live period row, rather than in a baseline table or on
  the archive: this table already holds exactly one row per `(club, category, period)`, so
  two columns add **zero rows**. The archive would be wrong — it is bounded to a display
  top-N, while a baseline is needed for every eligible club. Baselines are stamped lazily,
  at first observation inside the period, which is what removes the pre-seeding pass, the
  "first period has no baseline" case, and the disable→re-enable hazard.
- **`LeaderboardAwardDefinition`** (table `leaderboard_award_definition`) — admin config:
  which chart awards, how often, to how many places. `name` (frozen onto each archive, used
  in the winner's inbox subject and the landing card), `category:LeaderboardCategory(enum)`,
  `cadence:AwardCadence(enum: WEEKLY, MONTHLY, QUARTERLY)`, `minimumScore:int` (don't pay a
  growth of 0 — otherwise a dead chart pays an idle club), `isEnabled:bool` (**default
  false**, so nothing awards by accident), `lastClosedPeriodKey:?string` +
  `lastClosedAt:?DateTimeImmutable` (runtime state, hidden on the admin form — an editable
  monotonic anchor re-opens closed periods when moved back and silently skips them when
  moved forward), `createdAt/updatedAt`. `OneToMany` → `placements`
  (`cascade: persist+remove`, `orphanRemoval`, so EasyAdmin's `CollectionField` works).
  `UNIQUE(category, cadence)` — **load-bearing**: it is what makes `(category, period_key)` a
  sound non-nullable claim key on the archive, since two definitions on one category but
  different cadences produce different period keys. `INDEX(is_enabled)`.
  **There is no placements-count column** — top N is the highest `place` among the child
  rows, one source of truth instead of two that can disagree.
- **`LeaderboardAwardPlacement`** (table `leaderboard_award_placement`) — one prize place.
  `place:smallint` (1-based), `prizePence:bigint`, `reputationDelta:int`. `ManyToOne` →
  `definition:LeaderboardAwardDefinition` (not nullable, **`CASCADE`** — a placement is
  config, meaningless without its definition), `rewardTemplate:?RewardTemplate`
  (`SET NULL` — deleting a template must not silently delete a prize place).
  `UNIQUE(definition_id, place)`. `toEffects()` synthesizes `ledger_delta` +
  `reputation_delta` from the two scalars (the `CompetitionTemplate::$victorPrize`
  precedent: the common cash case needs no `RewardTemplate` row) and appends the attached
  template's effects **only when it `isActive()`**, so deactivating a template stops it
  paying out without unwiring every definition. A real child entity rather than a json
  place→reward map on the definition, for three reasons: referential integrity to
  `RewardTemplate`, `CollectionField` + a plain `FormType` being EasyAdmin's native idiom
  (a json column with `setFormType()` hits the documented injected-`CollectionType`-options
  trap), and consistency with `CompetitionTemplate`'s own `RewardTemplate` FK.
- **`LeaderboardPeriodArchive`** (table `leaderboard_period_archive`) — immutable header,
  one per closed period. The `SeasonRecord`/`SeasonSnapshot` shape, and **it carries the
  close job's claim**. `category:LeaderboardCategory(enum)` and
  `cadence:AwardCadence(enum)` (**denormalised**), `definitionName` (frozen admin label),
  `periodKey`, `periodStartsAt`/`periodEndsAt` (**frozen instants**, end exclusive, so
  nothing downstream re-parses a period key), `baselinePeriodKey:?string` (which close last
  supplied the baselines this one consumed), `entryCount:int` (**integer, not smallint** —
  the eligible club count can exceed 32767), `archivedEntryCount:smallint`,
  `placementsAwarded:smallint`, `status:PeriodArchiveStatus(enum: PENDING, ARCHIVED,
  SKIPPED)`, `claimedAt` (the lease clock), `closedAt:?DateTimeImmutable`. `ManyToOne` →
  `definition:?LeaderboardAwardDefinition` (**nullable, `SET NULL`** — the archive must
  outlive an admin deleting the definition, the `UserLedger::$sourceSyncRecord` precedent;
  the link is admin convenience, never identity).
  `UNIQUE(category, period_key)` is the claim gate, and **both columns are NOT NULL so it is
  a plain unique, not a partial one** — which is the entire reason `category` and `cadence`
  are denormalised here instead of keying on the nullable FK. No `options: ['where' => …]`,
  so neither the `pg_get_expr` predicate-churn trap nor the `EntrantRewardClaim`
  two-partial-index dance applies. Plus `INDEX(status, period_ends_at)` and
  `INDEX(cadence, closed_at)` (the "last month's winners" read).
  `CLAIM_LEASE_SECONDS = 300` — the same constant name and value as `CompetitionRound`, so
  the two are recognisably one pattern. Constructor-only for every identity field, with
  exactly three mutators: `markArchived()`, `markSkipped()`, `reclaim()`.
  `ARCHIVED_ENTRY_LIMIT = 100`.
- **`LeaderboardPeriodArchiveEntry`** (table `leaderboard_period_archive_entry`) — one
  frozen row of a closed board, and, when it placed, **the award record itself**.
  `rank` (column `rank_position`), `clubId:string(36)` and `clubName` (**denormalised, no
  FK** — the `SeasonRatingsSnapshot`/`ClubSpotlight` precedent, so the row survives club
  deletion), `userId:?string(36)` (the credited account, denormalised and indexed so
  "awards won by this account" is one query; **nulled, not deleted, on account erasure**),
  `score:bigint` (the growth as ranked), `cumulativeScore:bigint`, `baselineScore:bigint`,
  `baselineMissing:bool`, `awardEligible:bool` (so **the archive explains its own
  exclusions**), `displayLabel:?string` (**frozen** — names the player who scored *in that
  period*; the live row overwrites it next week, which is the point of freezing),
  `homeKitConfig`/`awayKitConfig`/`badgeConfig:?array` + `countryCode:?string(2)` (frozen
  club identity, so the landing page's "any club listed anywhere shows at least its badge"
  rule keeps holding for a deleted club), `awarded:bool`, `place:?smallint`,
  `appliedEffectsJson:?array` (frozen effects, the `EntrantRewardClaim::$appliedEffectsJson`
  precedent), `inboxMessageId:?string(36)` (**a plain string, NOT a FK** — inbox messages
  cascade-delete with the club, and an award must not vanish with its delivery receipt;
  admin forensics only). `ManyToOne` → `archive` (not nullable, `CASCADE`),
  `rewardTemplate:?RewardTemplate` (`SET NULL`).
  `UNIQUE(archive_id, club_id)`, `UNIQUE(archive_id, rank_position)`, and
  `UNIQUE(archive_id, place) WHERE (place IS NOT NULL)` — **the one partial index in the
  feature**, because `place` is nullable and PostgreSQL treats NULLs as distinct. Its
  predicate is declared on the entity exactly as `pg_get_expr(indpred, indrelid)` reports
  it, so `schema:create` builds it on a fresh `wunderkind_test` and `migrations:diff` does
  not churn. Plus `INDEX(club_id)` and `INDEX(user_id)`.
  Folding the award into this row rather than a separate `leaderboard_award` table is
  deliberate: that table would buy a `UNIQUE(archive, place)` idempotency key, but the
  header's own unique is already the claim gate and the partial index above gives the
  per-place guarantee anyway. Two tables describing the same fact is worse.
- **`TacticalAdvantage`** — lookup table of style×style multipliers.
  `style/opponentStyle:PlayingStyle(enum)`, `multiplier:float`. No
  relations.

## Player / squad

- **`Player`** — a footballer. `firstName/lastName`, `dateOfBirth`,
  `nationality`, `position:PlayerPosition(enum)`,
  `status:PlayerStatus(enum, ACTIVE)`,
  `recruitmentSource:RecruitmentSource(enum)`, `potential/currentAbility/
  contractValue`, `pace/technical/vision/power/stamina/heart`,
  `height/weight`, `morale:int(50)`, `appearance:?array`. Embeds
  `personality:PersonalityProfile` (Embeddable — see below, not its own
  table). `OneToMany` → `guardians` (cascade persist/remove,
  orphanRemoval); `ManyToOne` → `agent:?Agent` (`SET NULL`);
  self-referential `ManyToMany` → `siblings:Collection<Player>`
  (`JoinTable: player_siblings`).
- **`PersonalityProfile`** — `#[ORM\Embeddable]`, **not a standalone
  entity/table**. `determination/professionalism/ambition/loyalty/
  adaptability/pressure/temperament/consistency:int(10)`, all on a 1–20
  scale (per docblock). Embedded into `Player`, `Scout`, `Staff`.
- **`PlayerArchetype`** — catalog of personality archetypes. `slug/name/
  description`, `polarity:ArchetypePolarity(enum)`,
  `traitWeights:array`. No relations. `name`/`description` are
  translatable (`TranslatableEntityType::PLAYER_ARCHETYPE` — see
  Translations below); `GET /api/archetypes?lang=` localizes them.
- **`PlayerCareerStat`** — current cumulative career stat row.
  `playerId/playerName`, `appearances/goals/assists`. `ManyToOne` →
  `Club` (not nullable, `CASCADE`). `appearanceConfig:?array` (json, sync
  v2) — the player's personal-traits-only avatar config (`hair/hairColor/
  headband/skin/face/facial/lip`, no kit colors — those are club-derived
  and never sent here); optional, stored verbatim with no validation, and
  only overwritten when the incoming sync entry carries the key at all
  (`array_key_exists`, not `??`) — an older client omitting it must not
  wipe out a value a newer one already sent. **Not** copied onto
  `PlayerCareerStatSnapshot` below (identity/cosmetic, not a stat worth
  historizing). `toFullAppearanceConfig()` merges this personal-traits
  config with `$this->club->getHomeKitConfig()`'s kit colors into one
  complete, renderable sprite config (returns `null` if no
  `appearanceConfig` has been reported yet; returns the personal traits
  alone, un-merged, if the club hasn't set its own kit identity either) —
  kit color is determined from the parent club, confirmed explicitly by
  the human, never stored on this row. Not wired into any endpoint yet —
  groundwork for whenever a consumer needs to actually render this.
- **`PlayerCareerStatSnapshot`** — point-in-time copy of the above, tied
  to a sync. `playerId/playerName`, `appearances/goals/assists`,
  `recordedAt`. `ManyToOne` → `Club` (not nullable, `CASCADE`),
  `syncRecord:?SyncRecord` (nullable, `SET NULL`).
- **`StaffCareerProfile`** (sync v2) — one row per currently-hired staff
  member, identity + avatar config only — **no stats/performance
  columns**, unlike `PlayerCareerStat` (there's no goals/assists/rating
  concept for staff). `staffId/staffName/staffRole` (all plain strings;
  `staffRole` is free text, **not** the `StaffRole` enum — the client's
  role set is wider, e.g. `scout`/`assistant_coach` aren't `StaffRole`
  cases and `Scout` is a separate entity backend-side), `appearanceConfig:
  ?array` (json; staff persist `outfit`/`trousers`/`glasses` too, unlike
  players' personal-traits-only subset above), `updatedAt`. `ManyToOne` →
  `Club` (not nullable, `CASCADE`). `UNIQUE(club, staffId)` — same
  pool-is-ephemeral reasoning as `PlayerCareerStat`'s `UNIQUE(club,
  playerId)` (Staff rows are pool-only and deleted on consumption, so
  there's no durable server-side Staff row to key off instead).
- **`Agent`** — a player's agent. `name`, `reputation:int(50)`,
  `commissionRate('10.00')`, `dob`, `nationality`, `judgements:array`,
  `experience`, `rating:int(50)`, `appearance:?array`. `OneToMany` →
  `players:Collection<Player>`.
- **`Scout`** — a club's scout. `name`, `dob`, `nationality`,
  `judgements:array`, `experience`, `appearance:?array`. Embeds
  `personality:PersonalityProfile`.
- **`Staff`** — coaching/other staff. `firstName/lastName`,
  `role:StaffRole(enum)`, `coachingAbility/scoutingRange:int(50)`,
  `weeklySalary`, `morale:int(50)`, `nationality`, `specialty`,
  `specialisms:?array`, `dob`, `hiredAt`, `appearance:?array`. Embeds
  `personality:PersonalityProfile`.

## Sponsorship / finance

- **`Sponsor`** — `company`, `nationality`, `size:CompanySize(enum)`,
  `isActive`, `monthlyPayment`, `contractStartDate/EndDate`,
  `reputationMinThreshold`, `reputationBonusThreshold`,
  `bonusMultiplier`, `status:SponsorStatus(enum)`,
  `earlyTerminationFee`, `assignedAt/lastPaymentAt`. `ManyToOne` →
  `club:?Club` (nullable — unassigned pool = null club).
- **`Investor`** — `company`, `nationality`, `size:CompanySize(enum)`,
  `isActive`, `tier:InvestorTier(enum)`, `investmentAmount`,
  `percentageOwned('5.00')`, `assignedAt/investedAt/lastPayoutAt`.
  `ManyToOne` → `club:?Club` (nullable).

## Messaging / admin comms

- **`AdminMessage`** (table `admin_message`) — operator announcement.
  `title`, `bodyHtml`, `targetType:MessageTargetType(enum)`,
  `priority:MessagePriority(enum)`,
  `displayType:MessageDisplayType(enum)`, `validFrom/Until`, `isActive`,
  `sendAsPush:bool(false)`, `pushSentAt:?DateTimeImmutable` — when
  `sendAsPush` is checked, the message also triggers an OS push
  notification (in addition to the normal poll queue) once, the first
  time it's saved Active with it on; `pushSentAt` is the guard against
  resending on a later edit. See `PushNotificationService` /
  `ResolveAdminMessageAudienceForPushMessageHandler` in `services.md`.
  `ManyToOne` → `createdBy:?Admin` (`SET NULL`); `ManyToMany` →
  `audienceGroups` (`JoinTable: admin_message_audience_group`);
  `ManyToOne` → `targetClub:?Club` (nullable, `CASCADE`).
- **`MessageDelivery`** (table `message_delivery`) — per-user delivery
  state. `deliveredAt`, `displayedAt`,
  `status:MessageDeliveryStatus(enum, PENDING)`. `ManyToOne` → `User`,
  `AdminMessage` (both not nullable, `CASCADE`).
- **`AudienceGroup`** (table `audience_group`) — a targeting segment.
  `name/slug`, `criteriaType:AudienceCriteriaType(enum, MANUAL)`,
  `criteriaPayload:?array`. Targeted via `AudienceGroupMember` and
  `AdminMessage`'s `ManyToMany`.
- **`AudienceGroupMember`** (table `audience_group_member`) — club↔group
  join. `joinedAt`. `ManyToOne` → `Club`, `group:AudienceGroup` (both not
  nullable, `CASCADE`).
- **`InboxMessage`** — a club's inbox item. `senderType:
  MessageSenderType(enum: AGENT, SPONSOR, INVESTOR, SYSTEM, COMPETITION, FRIENDLY)` —
  **corrected 2026-10-31**: this previously listed `GUARDIAN`/`STAFF`/`BOARD`, which have
  never existed, and omitted the real `SPONSOR`/`INVESTOR`/`SYSTEM`. The distinction is
  load-bearing, not cosmetic: `InboxService::acceptMessage()` routes **only** `COMPETITION`
  and `FRIENDLY` to the effects applier, so a reward message sent as `SYSTEM` renders as
  claimable and then applies nothing, silently (see `LeaderboardAwardDeliveryService`'s
  docblock). `senderName/subject/body`, `offerData:?array` (carries `type`,
  `competitionId`, `matchId`, `challengeId` metadata for tournament/friendly messages, and
  **the reward effects themselves** — `offerData.effects`, a list of `ledger_delta` /
  `reputation_delta` / `unique_asset_grant` payloads. There is **no `effects` column**; this
  doc previously listed one, and also listed a `hall_of_fame_points` effect type that no
  code has ever handled. `offerData.effects` being non-empty is also what
  `InboxController::serializeMessage()` derives `requiresAccept` from, so its position at the
  top level of `offerData` is load-bearing),
  `status:MessageStatus(enum: UNREAD, READ, ACCEPTED, REJECTED, DISMISSED)`, `relatedEntityType/Id:?string`,
  `respondedAt`. `ManyToOne` → `Club` (not nullable, `CASCADE`).
- **`IdempotencyRecord`** (table `idempotency_record`) — 24h idempotency cache for club-scoped write endpoints (`POST /api/competitions/*`, `POST /api/friendlies/*`, `POST /api/inbox/*`). `clubId:string`, `idempotencyKey:string`, `endpoint:string`, `requestHash:string` (SHA-256 of request body), `statusCode:int`, `responseBody:string`, `createdAt`, `expiresAt`. Unique index `uq_idempotency_club_key_endpoint` on `(club_id, idempotency_key, endpoint)`.
- **`FriendlyChallenge`** (table `friendly_challenge`) — a friendly match challenge on the challenge board (`App\Entity\Friendly\FriendlyChallenge`). Viewable in the admin under **Competitions -> Challenges** (`FriendlyChallengeCrudController`, read-only — see `04_interfaces/output/controllers.md`). `challengerClub:?Club` (`ON DELETE SET NULL`), `challengerClubId:string` (column `challenger_club_uuid`), `challengerClubDisplayJson:array`, `acceptorClub:?Club` (`ON DELETE SET NULL`), `acceptorClubId:?string` (column `acceptor_club_uuid`), `acceptorClubDisplayJson:?array` (null means **not yet accepted** — load-bearing, checked in three places; do not default it to `[]`), `venue:string` (`'home'|'away'` from the poster's perspective), `stakePence:int`, `status:FriendlyChallengeStatus(enum: OPEN, ACCEPTED, COMPLETE, CANCELLED, EXPIRED)`, `createdAt`, `expiresAt`, `acceptedAt`, `kickoffAt`, `completedAt`, `homeScore/awayScore:?int`, `resultJson:?array` (stores `homeClubJson`, `awayClubJson`, `homeLineupJson`, `awayLineupJson`, `narrativePayload`, `winnerClubId`). Squads live in `friendly_challenge_squad` (keyed by `FriendlySide`, one row per participant — *not* home/away, since `venue` flips which side is home per challenge) and the event log in `friendly_challenge_audit`, both for the reasons in `schema.md`'s "The split rows": `GET /api/friendlies/challenges` is public and decoded two whole squads per listed challenge.
- **`GameEventTemplate`** — a random-event definition. `slug`,
  `category:EventCategory(enum)`, `weight`, `title`, `bodyTemplate`,
  `impacts:array`, `firingConditions:?array`, `severity`, `noInteract`,
  `chainedEvents:?array`. No relations. See also
  `docs/event-guide.md`. The `MATCH_NARRATIVE` category (added
  2026-09-19) diverges from the description above: rows are chain-graph
  nodes (slug `{NODE_TYPE}_{N}`, ported verbatim from
  `wunderkind-app`'s `narrativeContent.json`), and `chainedEvents`
  there means a node-type graph edge, not an NPC-interaction weight
  boost — see `MatchNarrativeGeneratorService` in `services.md` and
  `SeedMatchNarrativeTemplatesCommand`.
- **`SocialAccountConnection`** — OAuth connection to a social platform.
  `platform:SocialPlatform(enum)`, `displayName`, `externalAccountId`,
  `accessToken` (encrypted, see `TokenEncryptionService`),
  `refreshToken`, `tokenExpiresAt`, `isActive`, `connectedAt/
  lastRefreshedAt`. No relations.
- **`SocialPostTemplate`** — templated social post copy.
  `category:StatCategory(enum)`, `platform:SocialPlatform(enum)`,
  `period:StatsPeriod(enum)`, `bodyTemplate`, `isActive`. No relations.

## Competition (`App\Entity\Competition`)

- **`CompetitionTemplate`** — a competition's static rules. `name/slug`,
  `entrantCapacity`, `durationOption:CompetitionDuration(enum)` — ten cases,
  `ONE_HOUR`/`TWO_HOURS`/`FOUR_HOURS`/`SIX_HOURS`/`TEN_HOURS`/`ONE_DAY`/
  `TWO_DAYS`/`ONE_WEEK`/`ONE_MONTH`/`TWO_MONTHS`, declared shortest-first
  because that order *is* the admin dropdown's order. The backing strings
  (`1h`..`2mo`) are persisted, so an existing case's value must never be
  edited; new cases are additive and need no migration. **The month cases are
  fixed 30/60-day windows, not calendar months** — a calendar `P1M` would make
  `toInterval()` (which sets `endsAt`) and `toHours()` (served to the client as
  `durationHours`) disagree by up to a day. `CompetitionDurationTest` pins both,
  and `CompetitionScheduleCalculatorTest` derives its matrix from `cases()`, so
  a future duration too short to give its rounds a visible window fails there
  rather than in production. For reference, the tightest pick today —
  `ONE_HOUR` over a 64-entrant bracket — is 600s a round, splitting 420s
  reveal / 180s intermission, both clear of `MIN_WINDOW_SECONDS` (120) and of
  the 1-minute draw/resolve cron tick.
  `allowedTiers:?array`, `minClubReputation/minClubAgeSeasons/
  entryFeePerRound/victorPrize`, `roundEngineConfig:?array`, `isActive`,
  `intermissionRatio:float` (default 0.3, validated strictly `0 < x < 1`
  in the same `validate()` method) — fraction of each round's time-budget
  spent in the post-results intermission before the next draw (also
  governs the lead time before round 1's own draw); see
  `CompetitionScheduleCalculator` in `04_interfaces/output/services.md`.
  `autoFillSpoofEntrants:bool` (default false) + `autoFillDelayMinutes:int`
  (default 20, one of `ALLOWED_AUTO_FILL_DELAY_MINUTES` = [5,10,20,30,60])
  — dev/testing convenience: once an instance's first entrant registers,
  auto-fill every remaining slot with spoof entrants after the delay, so
  a solo tester isn't stuck waiting on real registrants — see
  `CompetitionAutoFillService` in `04_interfaces/output/services.md`.
  `trophy:?array` (json, nullable — same column and shape as `League`'s, see
  `League` above); read live off the template by the API rather than copied
  onto `ActiveCompetition`, since trophy design is cosmetic, not a
  live-bracket-integrity concern like `entrantCapacity`/`durationOption` —
  so re-skinning a cup updates every competition already running under it. `ManyToMany` → `rewardTemplates` (`JoinTable:
  competition_template_reward_template`).
- **`ActiveCompetition`** (table `active_competition`) — one live
  instance of a template. `status:ActiveCompetitionStatus(enum)`,
  `entrantCapacity`, `durationOption:CompetitionDuration(enum)`,
  `registrationOpenedAt`, `lockedAt/startsAt/endsAt/completedAt/
  cancelledAt`, `cancellationReason`. `ManyToOne` → `template:
  CompetitionTemplate` (not nullable, `CASCADE`). Migration
  `Version20260916112510` adds a **partial unique index**
  (`uq_active_competition_one_open_per_template`, `WHERE status =
  'registering'`) — only one open competition per template at a time.
- **`CompetitionEntrant`** (table `competition_entrant`) — a club's
  registration, display half only. It used to carry the whole submitted
  snapshot — squad, staff, tactics, facilities, every player's attributes and
  appearance — in one `snapshotJson` column, which was the heaviest column in
  this schema. That column had four readers with four unrelated read profiles
  and ORM 3 removed `PARTIAL`, so every reader decoded all of it: a single
  public `GET /api/competitions/{id}` decoded up to 64 whole squads and
  exhausted the request memory limit, and the admin's Club Snapshots index had
  already timed out (504) in production for the same reason.
  **Now split by who reads what** (see `schema.md`'s "The split rows"):
  `clubDisplayJson:array` is a bounded, picked key set from
  `CompetitionRegistrationService::clubDisplayBlock()` — deliberately *not*
  the normaliser's raw output, which forwards every unrecognised client key
  verbatim and so would let one registration inflate every later read.
  `clientSnapshotAt:?string(64)` is stored as the string the client sent, not
  parsed, because the API echoes it back and a DateTimeImmutable round-trip
  could change the rendered offset. `squadPlayerCount:smallint` is denormalised
  so the admin index can show it without touching a squad row.
  `facilities` is gone entirely — accepted, stored on every entrant, decoded on
  every read, and never read back by anything.
  Plus `seed`, `status:CompetitionEntrantStatus(enum)`, `snapshotLockedAt`,
  `snapshotVersion:int(1)`, `registeredAt`. `ManyToOne` → `activeCompetition`
  (not nullable, `CASCADE`); **`club:?Club` (NULLABLE, `SET NULL` since
  2026-11-02 — it was not nullable / `CASCADE`)**; `eliminatedInRound:?CompetitionRound`
  (nullable, `SET NULL`). Unique index on (`active_competition_id`, `club_id`) —
  one entry per club per competition, and unaffected by the nullability because
  Postgres treats NULLs as distinct.
  **An entry now OUTLIVES its club, and that is the point.** `competition_fixture`'s three
  entrant columns are ON DELETE SET NULL, so cascading the entrant away with its club blanked
  that club out of every bracket it had ever played in — a completed cup's opposition reverted
  to "To be decided", scorelines included. It fired routinely, not rarely: competitions are
  filled by `CompetitionAutoFillService` with spoof clubs and `app:clubs:purge-spoof` ran on
  EVERY deploy in both workflows until 2026-11-02, so each deploy emptied the finished cups.
  **The deploy step has since been removed** — purging is an operator action (admin →
  "Clear Spoof Clubs") — but a club can still be deleted whenever an account is, so this
  column is the fix, not the workflow change. Keeping the row
  keeps `clubDisplayJson` with it, which is all a bracket renders.
  Consequences to hold onto: **every reader must handle a null club** — prefer
  `getDisplayName()` / `getClubIdValue()` / `getClubDisplayJson()` wherever the club is only
  being DISPLAYED, and reserve `getClub()` for paying, mailing or pushing to its owner, which
  skips a null entry rather than guessing. And `AccountDeletionService::releaseUnstartedCompetitionEntries()`
  still deletes entries in a REGISTERING competition, because an orphan there would hold a
  capacity slot for ever and `lockIfComplete()` waits on every entrant having a stored squad —
  the same split as the FriendlyChallenge line beside it (an unaccepted challenge goes, a
  played one stays). **No association to
  `CompetitionEntrantSquad`** — see the two rules in `schema.md`.
  `squadState:EntrantSquadState` (`pending`/`stored`/`failed`, default `stored`) records
  whether the queued squad/submission write has landed — registration reserves the slot
  and queues the bulky write, so an entrant briefly holds a capacity place with no XI.
  **A separate axis from `status`, deliberately**: `status` is serialized to clients, and
  a reserved entrant really is REGISTERED. `CompetitionDrawService` refuses to draw a
  competition holding any non-`stored` entrant. Capacity needed no change —
  `countForCompetition()` counts everything but WITHDRAWN, so a pending entrant holds its
  slot for free.
- **`CompetitionEntrantSquad`** (table `competition_entrant_squad`) — the
  match-engine half: `playersJson` (the XI, exactly
  `SnapshotValidator::REQUIRED_PLAYERS`), `staffJson`, and `clubEngineJson`
  holding the three club keys only `DeterministicEngine` reads (`cohesion`,
  `managerMotivation`, `lowUrgency`). `OneToOne` owning side →
  `entrant:CompetitionEntrant` (not nullable, `CASCADE`), unique on
  `entrant_id`. Nothing on a client read path may load this. Pruned when a
  competition completes, since no further fixture can resolve.
- **`CompetitionEntrantSubmission`** (table `competition_entrant_submission`) —
  the registration envelope exactly as the client posted it: `envelopeJson`
  (`club`, `players`, `staff`, `facilities`, `clientSnapshotAt`, and whatever
  the client adds next without a contract change) plus `submittedAt` (server
  clock, distinct from the client's own `clientSnapshotAt` inside it).
  `OneToOne` owning side → `entrant:CompetitionEntrant` (not nullable,
  `CASCADE`), unique on `entrant_id`; no inverse, as above. **Not pruned on
  completion** — unlike the squad row, which is the reason it is a separate
  table. These are the largest rows in the domain (a measured submission is
  ~1.4MB, nearly all of it `club` history): never load them on a page that
  renders many entrants. Contents are untrusted client data.
- **`FriendlyChallengeSubmission`** (table `friendly_challenge_submission`) —
  the friendly twin, keyed by `side:FriendlySide` rather than venue, unique on
  (`challenge_id`, `side`). Written through
  `FriendlyChallengeSubmissionRepository::upsertSide()`, mirroring the squad
  repository's own method. Same retention and same cautions.
- **`CompetitionResultAudit`** (table `competition_result_audit`) — the
  forensic half of a result: `eventLogJson`, `computationAuditJson:?array`.
  Between them these had exactly one reader in the repository (the admin
  competition detail page) while being decoded on every public bracket read,
  every `GET /api/matches/{id}` and every club-hub query. `OneToOne` owning side
  → `result:CompetitionResult` (not nullable, `CASCADE`). **Not** pruned on
  completion, unlike squads.
- **`CompetitionResponseCache`** (table `competition_response_cache`) — the
  prebuilt viewer-independent body of `GET /api/competitions/{id}`: `payload`,
  `stateFingerprint:string(64)`, `payloadVersion:smallint`, `generatedAt`.
  `OneToOne` → `activeCompetition` (unique, `CASCADE`). Self-validating: a read
  recomputes the fingerprint and treats a mismatch as a miss. **The fingerprint
  is a hash over an explicit labelled tuple, not a number** — it was an additive
  integer and that collided (the entrant count excludes withdrawn rows while the
  snapshot-version sum includes them, so a withdrawal netted -1 and another
  club's resubmission +1, they cancelled, and a stale bracket was served behind a
  304) as well as omitting the competition's status/cancellation/completion and
  every template field the document embeds. **Read and written through DBAL, never
  the ORM**: a failed ORM `flush()` closes the shared EntityManager, which in a
  cron tick stranded the next claimed round permanently, and the identity map hid
  other writers' rebuilds from the re-check inside the advisory lock. For
  a COMPLETED competition this row *is* the archive — its document can never
  change again. Database-backed rather than a Symfony cache pool because only
  Postgres has a volume and `up -d` recreates the app container on every deploy,
  so `var/cache` does not survive one.
- **`CompetitionRound`** (table `competition_round`) — one round of a
  competition. `roundIndex`, `label`,
  `status:CompetitionRoundStatus(enum: DRAW_PENDING|DRAWN|
  RESULTS_PUBLISHED|CANCELLED)`. Draw and resolve are two decoupled,
  independently-scheduled/claimed phases (see `CompetitionDrawService`/
  `CompetitionResultsService` in `04_interfaces/output/services.md`):
  `scheduledAt` = this round's **draw** due-time (round 1 fixed at lock
  time; round N>1 (re)written by `CompetitionResultsService` the instant
  round N-1's results publish, not fixed up front); `startedAt` = when
  actually drawn; `matchesResolveAt:?\DateTimeImmutable` = this round's
  **resolve** due-time, set the instant it's drawn; `completedAt` = when
  results actually published. `matchEngineIdentifier:?MatchEngineIdentifier
  (enum)`. Two separate claim-lock columns — `drawLockedAt` (draw-phase
  claim) and `resolveLockedAt` (resolve-phase claim) — since the same row
  is claimed twice in its life; one shared column can't safely serve both
  (the second claim would be indistinguishable from "already drawn").
  `reminderSentAt` (claim-lock for the separate `ROUND_RESOLVING_SOON`
  push, sent to a DRAWN round nearing its `matchesResolveAt` — see
  `CompetitionRoundReminderService` in `04_interfaces/output/
  services.md`). `ManyToOne` → `activeCompetition` (not nullable,
  `CASCADE`).
- **`CompetitionFixture`** (table `competition_fixture`) — one matchup
  within a round. `slotIndex`, `status:CompetitionFixtureStatus(enum)`,
  `processedAt`. `ManyToOne` → `round` (not nullable, `CASCADE`);
  `homeEntrant/awayEntrant/winnerEntrant:?CompetitionEntrant` (all
  nullable, `SET NULL`).
- **`CompetitionResult`** (table `competition_result`) — a fixture's
  outcome. `homeScore/awayScore` (the true final score, including extra
  time if played — never affected by a shootout),
  `homeClubJson/awayClubJson:array` (denormalized club display data —
  kit colours, badge, stadium — copied from the entrant snapshot so a
  client can render kits without a second lookup),
  `homeLineupJson/awayLineupJson:array` (starting XI with
  goals/assists/cards/ratings, admin-only), `narrativePayload:?array`
  (full generated commentary timeline, see
  `MatchNarrativeGeneratorService` in `services.md`),
  `wentToExtraTime/wentToPenalties:bool(false)`,
  `penaltyHomeScore/penaltyAwayScore:?int` (a knockout fixture can never
  end level — these record how a tie was actually settled; the shootout
  winner is a literal coin flip, see `DeterministicEngine`),
  `engineIdentifier:MatchEngineIdentifier(enum)`,
  `computationAuditJson:?array` (engine-agnostic audit trail detailing how
  the result was derived: requested/resolved engine, fallback/delegation
  notes, RNG seed, step-by-step dominance calculation per player and team
  multiplier, regulation & extra-time Poisson xG and rolls, penalty
  shootout rolls, goal/assist/card weights and rolls, player rating math,
  and outcome resolution method; admin-only, never sent in
  `toClientSummary()`), `generatedAt`.
  `OneToOne` → `fixture:CompetitionFixture` (not nullable, unique,
  `CASCADE`) — one result per fixture.
- **`RewardTemplate`** (table `reward_template`) — a claimable reward
  definition. `slug/name`, `description`, `effects:array`, `isActive`.
  Reverse side of `CompetitionTemplate`'s `ManyToMany`; referenced by
  `EntrantRewardClaim`.
- **`EntrantRewardClaim`** (table `entrant_reward_claim`) — a claimed
  reward instance. `triggerContext`, `appliedEffectsJson:array`,
  `claimedAt`. `ManyToOne` → `entrant` (not nullable, `CASCADE`),
  `rewardTemplate:?RewardTemplate` (nullable, `SET NULL`).

## Config / singleton "tuning" entities

Each is effectively a single global-config row.

- **`GameConfig`** — very large singleton (~100+ scalar/array fields)
  covering clique, bond/morale, injury, coaching, facility, transfer,
  sponsor/investor, youth, cooldown, squad-role, and social-post tuning
  constants. **Money fields are PENCE in the column and POUNDS in the admin
  form** — the Twig input divides by 100 and `DashboardController` multiplies
  by 100 on save (`smallSponsorMin`, `youthScholarshipWagePence`, …). Never
  convert at the read; the client treats these as pence.
  `youthScholarshipWagePence` (added 2026-10-31, default 25000 = £250/wk) is
  the flat weekly wage for a player SIGNED below the first-team age, and does
  not touch academy INTAKE players, who join on no wage. Its default mirrors
  the app's own `DEFAULT_GAME_CONFIG`, which is what clients use until this
  field reaches them — the two must not drift, and the app pins them equal in
  a test. Notable array fields: `bankruptcyDeductionTiers`,
  `maxSponsorsByTier`, `maxInvestorsByTier`, `npcClubBalanceRanges`,
  `npcFacilityLevelRanges`, `npcClubSizeWeights`,
  `squadRoleAppearanceExpectations/MoraleDecayPerWeek/
  MoraleBoostPerWeek/AutoAssignThresholds`,
  `wageMultiplierTiers`, `leagueWinPoints`, `pyramidNewsConfig`,
  `statPostRotation/Schedule/LastRunAt`, `leagueTierDefaults` (new — json,
  keyed `"1"`-`"8"`, each `{promotionSpots, tvDeal, prizeMoney,
  leaguePositionPot, sponsorCount, trophy}` — `trophy` being the same nullable
  sprite config `League::$trophy` holds. **Rows are normalised on read AND
  write** (`GameConfig::normaliseTierRow()`): this is plain stored JSON, so a
  row saved before the trophy component changed still carries the retired
  `trophyImage`/`trophyColour` pair, as does any config export file taken back
  then — both are folded through `TrophyConfigFactory::fromLegacy()` rather
  than losing their trophy. Replaces the
  old hardcoded `LeagueService::LEAGUE_TIER_DEFAULTS` const as the source
  `generateLeaguesForCountry()` reads, and is what the admin "League Tier
  Defaults" bulk-edit screen on `admin_leagues_overview` writes to — see
  CLAUDE.md's "World Generation" section). Fetched/created via
  `GameConfigRepository::getConfig()`. No relations. Its own dead,
  differently-shaped `npcSquadConfig` field (never read by generation code,
  formerly exposed verbatim at `GET /api/game-config`) was removed —
  `StarterConfig::$npcSquadConfig` below is, and always was, the real config.
- **`PoolConfig`** — singleton tuning for recruitment-pool generation
  ranges (player/coach/scout/agent age/ability/height/weight ranges and
  pool targets per role). No relations.
  **`playerFirstTeamAgeMin`** (default 19, `Version20261015213517`) is the youngest a
  player may be in a STARTER PACK or a generated NPC squad, read via
  `firstTeamAgeRange()`. It exists because `playerAgeMin/Max` alone could not serve the
  whole game: the pool must span 12-14 for `GET /api/players/youth` to draw from, while
  first teams need adults — `playerAgeMin/Max` say what the pool CONTAINS, this says who a
  senior squad may take out of it. Before it, `StarterPackService` drew on
  ability/position/nationality with **no age condition at all**, so a youth-aged pool
  produced squads of 11-12 year olds, and NPC squads generated from the same global range
  had the same problem. **19, not 18, is deliberate**: the backend sends only
  `dateOfBirth` and the client derives age against the IN-GAME clock, which runs slightly
  behind real time, so a backend-exact 18 can display as 17.
  **Youth ability ceilings** (`playerJuniorAbilityCeilingAge/Percent`,
  `playerYouthAbilityCeilingAge/Percent`, defaults under-16 → 10% and
  under-20 → 20%, added by `Version20261015072452`) cap how much of a
  generated player's potential is already realised, read via
  `abilityCeilingFractionForAge()` in
  `PlayerGenerationService::buildAbilityTarget()`. The ceiling caps the
  ability TARGET, which caps every attribute, and `currentAbility` is their
  mean — so the realised ratio lands at or below the ceiling and can never
  exceed it. Applied as a final `min()` over whichever age branch ran,
  prodigy included, so a branch added later cannot bypass the rule; within a
  band the draw spans half the ceiling to the ceiling so an intake is still a
  spread of prospects. Measured at potential 90: a 13 year old now generates
  at CA 3–8 (was ~35), a 17 year old at 7–16, a 22 year old unchanged at
  35–62. **These shape future generation only** — existing pool rows keep the
  ability they were built with; regenerate the pool to apply them
  retroactively. Editable on the Player Pool admin screen
  (`templates/admin/pool_player_config.html.twig`), and covered automatically
  by `ConfigImportExportService`'s reflection-driven export.
  **Youth-draw home-nationality mix** (`youthHomeNationalityPercentLocal/
  Regional/National/Elite`, defaults 95/85/70/45, added by
  `Version20261015093949`) is the per-tier share of `GET /api/players/youth`
  that should carry the requested country, read via
  `youthHomeNationalityPercents()`. Moved out of a `YouthPoolService` const so
  the intake mix is tunable from the same admin screen. With the defaults a
  200-player draw returns ~175 home / ~25 foreign (12.5%) — but those 25
  concentrate at the top, the elite band alone being 55% foreign, which is why
  a draw scanned best-first looks far more international than the headline.
  Targets, not guarantees: the endpoint draws rather than generates, so a tier
  short of home-nationality players falls back to whatever exists and
  `distribution.nationalityMatched` reports what was achieved.
  `YouthPoolService::TIER_WEIGHTS` deliberately stays a constant — it is the
  shape of a pyramid, not a balance knob.
- **`StarterConfig`** — singleton for new-club starter setup (starting
  balance, starter counts of players/coaches/scouts/managers,
  `starterSponsorTier`, `starterClubTier`, `defaultFacilities`,
  `starterReputationTier`, `enabledCountries`, `leagueAbilityRanges`,
  `npcSquadConfig`, `fanBaseRanges`, promotion/relegation fan-base
  multipliers, `setupTutorialConfig`). `id:int = 1` fixed. No relations.
  **`setupTutorialConfig:json` (added 2026-11-02, `Version20261102121403`)** — the optional
  first-run guidance block served as `tutorial` on `GET /api/starter-config`, seeded with the
  5 briefings + 10 notes the app already shipped as a fixture. NOT NULL with a PHP default
  like the other five JSON columns here; the migration adds a DB default purely to populate
  the single existing row and then drops it, because leaving it behind is schema drift (the
  first draft did, and the next `migrations:diff` emitted `ALTER ... DROP DEFAULT`). **Named
  `setupTutorialConfig`, not `tutorialConfig`, to stay clear of `Club::$tutorialCompletedAt`**
  — a different, per-club concept, and the near-collision matters because
  `ConfigImportExportService` is reflection-driven over property names. Validated by
  `SetupTutorialValidator` on admin save and by `app:check-setup-tutorial`, because the
  consumer reports nothing: the app's `parseTutorial` cannot throw, so a bad item is dropped
  in silence. See `docs/api/setup-tutorial.md`.
  `npcSquadConfig` is keyed by tier string `"1"`-`"8"`, each entry:
  `playerMin/playerMax, managerCount/coachCount/chairmanCount,
  directorOfFootballCount/facilityManagerCount/scoutCount, foreignPercent`
  — this is the live config that sizes every NPC club's staff/scouts during
  world-pack generation (`WorldInitializationService`), edited via the "NPC
  Squad Config" admin screen (`DashboardController::starterConfig()`), and
  the same shape exposed at `GET /api/game-config`'s `npcSquadConfig` key
  (sourced from here, not `GameConfig`). See
  `docs/api/npc-club-staff-scouts.md`.

## Location / land economy (`App\Entity\Location`)

Replaces the hardcoded place data that used to live in
`CountryContentRegistry::PLACE_NAMES_BY_COUNTRY`. Adding a town is now an admin
action, not a deploy — see `docs/world-generation/adding-a-country.md`.

- **`CountryProfile`** (table `country_profile`) — per-country data with **no home in
  `App\Enum\Country`**, plus the readiness gate. **PK is the natural key `code
  varchar(2)`**, equal to `Country::$value`, which is what lets `location.country_code`
  be a real FK without resolving a code five existing columns already store as a bare
  string (precedent: `StarterConfig::$id = 1`). `name`/`defaultLocale` are **mirrored**
  from the enum by the seeder so a label can be corrected without a deploy.
  `isoAlpha3`, `continent`, `defaultTimezone`, `currencyCode`,
  `landPriceCurrencyCode`, `distanceUnit:DistanceUnit(km|mi)`,
  `status:CountryProfileStatus(draft|tier_ready|live|retired)`,
  `tierStructureReadyAt`, `locationsLiveAt`, `bbox:?array` (json) and
  `landPenceMin/Max:?int`.
  **Three nullable json columns carry operator-authored club-naming content** —
  `prestige_suffixes`, `generic_suffixes`, `stadium_formats`, added 2026-11-11. NULL is the
  normal state: the 25 countries authored in `CountryContentRegistry` keep their PHP consts,
  which remain the fallback, and nothing was migrated. **Three columns rather than one blob**
  because every layer above is already per category, and a column each makes the tri-state a
  database fact. **The setters coerce `[]` to null** and that is load-bearing, not tidiness:
  an empty list reaches `$pool[array_rand($pool)]` in `NpcClubGenerationService`, and
  `array_rand([])` throws. Read them through `CountryNamingContentProvider`, never directly.
  **Deliberately absent from `CountryProfileCrudController`** — raw-JSON textareas would
  bypass `NamingContentValidator`, and one of these lists is fed to `sprintf()`.
  **Two more nullable json columns carry the person-name pools** — `first_names`,
  `last_names`, added 2026-11-12 (`Version20261112010000`), same shape and same reasoning:
  NULL means "follows the shipped pool", `NamePoolRegistry` is the fallback, nothing was
  migrated, and the setters coerce `[]` to null because an empty pool reaches `array_rand([])`
  too. **A column per list, not one blob**, because an operator may override surnames and
  leave first names on the shipped pool — the source badge is per list. **Not a child table**:
  a name carries nothing (no provenance, status or approval), where a `location` does, so 300
  strings ≈ 2 KB per country at target is a bag, not an entity. Read them through
  `NamePoolProvider`, never directly — it is the only memoised provider here, and the reason
  is in its docblock: `MarketPoolService` clears the identity map every fifty players, so
  Doctrine is not caching behind it, and a full `app:pool:warm` reads these ~116,000 times.

  **`bbox` and the land band exist for `EnrichmentValidator`** — they are what catches
  an AI coordinate in the wrong country (there is a Birmingham in Alabama) and a
  hallucinated valuation. **`App\Enum\Country` is NOT retired**: it remains the
  canonical code list and the type-hint across ~24 files, and **no FK was added to the
  five existing `varchar(2)` country columns**.
- **`Location`** (table `location`) — one real town. `id` is a **UuidV7, deliberately
  not a slug of the name**: towns get renamed and claims point here.
  `country:CountryProfile` (**RESTRICT** — carries claims and real money; retire
  instead), `slug`, `town`, `townNormalised` (via `ClubNameNormalizer::normalize()`),
  `localisedNames:?array` (json), `region`, `lat`/`lng:?decimal(9,6)`, `population`,
  `populationSource`, `populationAsOf`, `landPence:?bigint`, `landValuedAt`,
  `landSource`, three `is*Locked` flags, `isCapital`, `citySize:CitySize`,
  `status:LocationStatus`, `rivalsComputedAt`, plus the enrichment columns
  (`enrichmentStatus:EnrichmentStatus`, `enrichmentClaimedAt`, `enrichedAt`,
  `enrichmentProvider`, `enrichmentModel`, `enrichmentAttempts`, `enrichmentError`).
  **`lat`/`lng`/`landPence` are NULLABLE, load-bearing**: "not known yet" is the normal
  pre-enrichment state, and `0,0` is a point in the Atlantic the rival pass would treat
  as real. **`citySize` is PERSISTED, not computed** — the 20/30/50 split is a property
  of the whole country's set, so computing per call would classify the same town
  differently as towns are added, while `NpcClub` already stores its own copy.
  **`enrichmentClaimedAt` is a LEASE** (`CLAIM_LEASE_SECONDS = 300`, same name/value as
  `CompetitionRound`): taking it is a conditional UPDATE and only the winner dispatches,
  which is what turns 50 simultaneous viewers into one paid API call.
  **The three `is*Locked` flags are set when an admin edits that field**, and enrichment
  skips a locked field — a hand correction is never undone by a re-run.
- **`LocationRival`** (table `location_rival`) — one of a location's four nearest rivals.
  `location`/`rivalLocation` (both CASCADE), `rank` (column `rank_position`),
  `distanceKm:decimal(7,2)`, `computedAt`. **Directional and that is intended** — a
  small town's nearest rival is the big city, not vice versa, so A may list B without B
  listing A. A table rather than a json column because `UNIQUE(location, rank)` plus a
  `CHECK rank BETWEEN 1 AND 4` expresses "bounded at four" in the database. **The two
  CHECK constraints are raw migration SQL only** — Doctrine has no CHECK mapping, and
  that is safe because `migrations:diff` ignores CHECKs entirely.
- **`LandPlot`** (table `land_plot`) — the buyable unit. **Claims and holds point here,
  not at the location.** `location` (**RESTRICT**), `plotNumber:smallint`, `label`,
  `landPenceOverride:?bigint` (null inherits the location's), `status`.
  `UNIQUE(location, plot_number)`. Seeding creates exactly one per town, so
  one-club-per-town holds until an operator adds more.
- **`PlotHold`** (table `plot_hold`) — a 15-minute exclusive option at a frozen price.
  `plot`/`user` (CASCADE), `landPence`, `stadiumEstimate` (**RESTRICT**),
  `stadiumPence` (denormalised), `expiresAt`, `consumedAt`, `releasedAt`.
  **The RESTRICT FK is what actually enforces the frozen quote** — a refresh physically
  cannot delete an estimate a live hold points at, so it supersedes instead.
  **Two partial uniques** settle the races: one active hold per plot, one per user.
  **Neither predicate can mention time** (a unique predicate must be IMMUTABLE, so
  `expires_at > now()` is illegal), so an expired-but-unreleased row still occupies the
  index — every read filters on expiry and `acquire()` releases exactly the blocking
  rows first. `app:plots:expire-holds` is hygiene, **not** required for correctness.
- **`PlotClaim`** (table `plot_claim`) — ownership, and the financial record.
  `plot`/`location` (**RESTRICT**), `user` (CASCADE, **the owner of record**),
  `club:?Club` (**nullable, SET NULL**), `clubNameAtClaim` (frozen),
  `landPencePaid`/`stadiumPencePaid:bigint`, `stadiumEstimate`, three
  `*LedgerEntry:?UserLedger` (all SET NULL), `sellBackPence`, `releaseReason`.
  **`UNIQUE(plot_id) WHERE released_at IS NULL`** is the one-club-per-plot guarantee.
  **Prices are copied in, never referenced**: land is revalued later and a sell-back
  needs both figures. **`club` is nullable because a plot can be bought before a club
  exists** and a claim must outlive its club — but note the consequence: the
  club-delete path must **RELEASE** the claim, because a nulled-but-active claim leaves
  a plot owned by nobody and permanently unbuyable
  (`PlotClaimRepository::releaseActiveForClubs()`).
- **`PlotClaimValuation`** (table `plot_claim_valuation`) — the sell-back working.
  **Split row**; `PlotClaim` declares no inverse association. Captures the land value
  **as at the sell-back**, not only what was paid, because land is revalued and a payout
  is otherwise unreproducible by the time a support ticket arrives. A reversal never
  edits it — it stamps `reversedAt` and points at a new opposing ledger row.
- **`StadiumEstimate`** (table `stadium_estimate`) — a cached build price.
  `location` (CASCADE), `pence:bigint`, `modelVersion`, `validUntil`, `supersededAt`.
  `UNIQUE(location_id, model_version) WHERE superseded_at IS NULL`.
  **Superseded, never deleted** (see `PlotHold` above). Priced on **real-world
  construction** (~£1,500/seat, so London is ~£135M) — the gap between that and a club's
  balance **is the progression**, since funding a flagship city is meant to take ten to
  fifteen clubs. There is deliberately **no affordability ceiling**.
- **`StadiumEstimateInput`** (table `stadium_estimate_input`) — split row holding
  `inputsJson`: every input AND every coefficient, so the arithmetic is reproducible
  after a retune. No inverse association.
- **`LocationEnrichmentRun`** / **`LocationEnrichmentAttempt`** — batch header and
  per-call history, modelled on `WorldPackGenerationRun`. Two tables rather than three
  because current state already lives on `Location::$enrichmentStatus`.
  `attempt.run` is **SET NULL** (history outlives a pruned run header);
  `rawResponseJson` is a split-row column in spirit — never hydrate these in bulk.
  The column is **`trigger_type`, not `trigger`**: TRIGGER is reserved SQL, and this
  table is written to with raw SQL (the `rank` → `rank_position` precedent).
- **`ClubDormancyWarning`** (table `club_dormancy_warning`, added 2026-11-04) — the
  EVIDENCE that a player was told their plot was about to be reclaimed. It does not drive
  the countdown; that is computed once in `PlotClaimRepository::findDormantClaims()` as
  `COALESCE(last_synced_at, created_at)`. `club` and `claim` both CASCADE.
  **`UNIQUE(claim_id, stage, dormancy_cycle_key)` and the cycle key is the load-bearing
  part**: a player can lapse, return, and lapse again, so keyed on `(claim, stage)` alone
  the second lapse would be silenced for ever and somebody would lose a plot having been
  warned only about a cycle they had already recovered from. The key is the `Y-m-d` of the
  activity timestamp the cycle was computed from. `inbox_message_id` is a **plain varchar
  with no FK** — inbox messages cascade with their club and this record must outlive its
  own delivery receipt. Row-then-send-then-stamp (the `AdminMessage::$pushSentAt` idiom),
  and `sentStagesByClaim()` filters on `sent_at IS NOT NULL` precisely so a failed send
  cannot count as a warning.

### NpcClub gained a location (2026-11-04)

`npc_club.location_id` -> `location(id)` **SET NULL**, nullable — the NPC half of what
`club.home_location_id` already did. Before it, an NPC club was a SNAPSHOT of a town (`region`,
`city_size`, `population_size`, `is_capital` copied at generation) rather than a reference to
one, so nothing could answer "which clubs play here?" and the copies had already drifted:
Leicester and Wakefield, both 355,000, classified into opposite bands on the two tables.

**Root cause of that drift, now fixed:** `LocationRepository::findForClassification()` had no
`ORDER BY`, and city size is a PERCENTILE split, so ties were broken on whatever order
PostgreSQL returned.

Those four columns are now **derived** — set from the town at generation, re-synced by
`app:npc-clubs:link-locations` and by the enrichment cascade. They stay as columns rather than
becoming accessors because they must survive the location being retired and because a cached
world pack reads them long afterwards. **Names are never derived**: renaming a town does not
rewrite existing clubs.

Nullable because a club legitimately has no town — generated before its country's locations
existed (`PLACEHOLDER_PLACES`), imported from a hand-edited file, or renamed by an admin.

### Town capacity replaced "one plot per town" (2026-11-04)

`land_plot` rows per town now follow city size (BIG 4, MEDIUM 2, SMALL 1) instead of being
hardcoded to one. `LocationCapacityService` is the single definition of who can buy:

```
available = plots − min(npcClubsHere, NPC_PLOT_CAP) − activeClaims
```

NPC clubs consume capacity **without holding a claim** — they carry a location, not a plot — and
are capped at 2 per town however many are generated there. Without the cap, nearly every town
would be sold out regardless of size, since generation puts ~144 clubs into a 40-town list.

Provisioning **tops up and never removes**: a plot can carry real money and `land_plot` is
RESTRICT-referenced by `plot_claim`, so a town that re-bands downward keeps what it has.

### Club columns added for the land economy (2026-11-04)

`Club` gained two columns and a partial unique:

- **`home_location_id`** → `location(id)` **SET NULL**, **nullable and staying that way**.
  Clubs founded before the land economy have no plot, and inventing a town for a live save
  would be worse than leaving it empty. This is the other half of the relationship
  `PlotClaim.club` has carried since the start — `PlotClaimService::attachClub()` existed
  with no caller at all until `ClubInitializationService` got one.
- **`name_normalised`**, maintained by `Club::setName()` and the constructor via the
  existing `ClubNameNormalizer` (whose rules the CLIENT mirrors in `src/utils/clubName.ts`).
- **`uq_club_name_per_location`** on `(home_location_id, name_normalised)`
  `WHERE (home_location_id IS NOT NULL)`. **Partial, or it would be useless**: the column is
  NULL for every pre-existing club and every spoof clone, and PostgreSQL treats NULLs as
  distinct. Partial also means existing duplicate names could not block the migration.
  Scoped **per location, not per country** — the name IS town plus affix, so two
  "Burnley FC"s only collide at Burnley.

### The economy model block on `GameConfig` (2026-11-12)

Nine columns, **every default meaning "behave exactly as before"**, added by
`Version20261112160100`. `economyWageModel` is `legacy`, all eight
`economyTierFactorsBp` are 10000 (parity), `inflationRatePerSeasonBp` is 0 — so
applying the migration cannot move a generated figure. Same compatibility
mechanism as `starter_config.max_enrichment_requests_per_user` (0 = unlimited):
the knob arrives switched off and is turned on per country by an operator.

- `economyWageModel` (`EconomyWageModel` enum: `legacy` | `turnover`).
  **`legacy` is a supported mode, not dead code** — it must keep producing
  byte-identical figures to the pre-unification generators.
- `economyTierFactorsBp`, `economyBaselineTurnoverPence` — json, eight rows,
  **indexed RAW by tier** (tier t reads row t). Both setters pad/truncate to
  exactly eight, because these are read by index and a short list resolves a
  deep country's lower tiers to null mid-chain, which surfaces as a wage of
  zero rather than an exception.
- `economyWageToTurnoverRatioBp` (6000), `economyStaffWageShareBp` (2500,
  capped below 10000 so the player budget cannot be zero),
  `economyWageCurveExponentBp` (25000 = k 2.5, floored at 1 — a zero exponent
  prices every ability the same), `economyReputationSpreadBp` (0 = flat 1.0).
- `economyWeeksPerSeason` — **46, which is NOT the backend's own financial
  year of 52** (`Club::crossedFinancialYearEnd()`). 46 is what the app charges
  wages for, and the reference-squad calibration is only meaningful against
  the payer's number. Six weeks of the backend's year are therefore unpriced
  by this model; recorded, not reconciled.
- `inflationRatePerSeasonBp` — **the rate and nothing else.** Never applied to
  a generated row, a stored value, or a world pack (the pack cache is keyed
  `(country, tier)` with no season dimension, so an inflated pack would be
  wrong for every save at a different season). Floored at 0: deflation would
  make a long save's ceiling approach zero, so the owner who played longest
  could invest least.

**`economyBaselineTurnoverPence` ships as eight ZEROS, which is not a usable
configuration and deliberately not a harmless one.** The wage scale divides by
turnover, so zero makes the turnover model *undefined* rather than cheap —
`EconomyScaleResolver` reports `isResolvable: false` instead of dividing. A
plausible-looking default here would be the dangerous choice: it would let the
model be switched on against numbers nobody authored.

`scaleTierToEight()` was **removed** in the same pass — see
`GameConfig::getNpcClubBalanceRangeForTier()`.

### `League` gained a TV pool, an authored size and a taper override (2026-11-12)

From `Version20261112180000`.

- **`tvPoolPence`** (bigint, nullable) — the league's whole TV money for a season. The
  AUTHORED value; `tvDeal` is derived from it as `intdiv(pool, size)`.
- **`tvDeal` keeps its old meaning**, the PER-CLUB share. Re-interpreting it as the pool would
  be a changed value under an unchanged key, which the BC doc counts as a break — shipped builds
  read it as one club's money.
- **`slots`** (smallint, default 0) — clubs in this league's table, the pool's divisor.
  **0 means "work it out"**, not "no clubs" (the `club_funding_minimum_pence` idiom).
- **`leaguePositionDecreasePercent`** (smallint, nullable) — per-league override of the
  position-pot taper, resolved `league ?? gameConfig`. Nullable rather than 0-defaulted because
  a taper of zero is a legitimate setting (every position pays the same), so `0` cannot also
  mean "not overridden".

**A NULL POOL DERIVES NOTHING, and that is the mechanism that makes this shippable.** Every
league starts with a null pool, so `tvDeal` keeps exactly the figure it was authored with.
Deriving unconditionally could not have been inert: `LeagueService::generateLeaguesForCountry()`
creates league rows BEFORE any club exists, so a fresh country would divide by zero or publish
its whole pool as one club's share.

**Why `slots` is authored and not counted.** A `League` row is a TEMPLATE shared by every save
in its country and tier — on 2026-11-12 England tier 8 had **147 real, non-spoof player clubs**
with `current_league_id` pointing at one row (tier 7: 9, tier 6: 5). The eighteen-club table a
player sees is assembled on their device from the world pack and exists nowhere on the server.
So nothing countable here means what a divisor needs to mean.

The derivation that is kept as the fallback — NPC rows plus one for the bottom tier — is right
for 9 of the 15 bootstrapped countries and wrong for 6. Measured on dev:

| countries | NPC rows by tier | the rule gives |
|---|---|---|
| EN, DE, IT | 18 x7, 17 | a clean 18 |
| AR, BR, ES, FR, NL, PT | 16 x7, 15 | a clean 16 |
| CA, JP, NG, PL, US | 8 x8 | 9 at the bottom, 8 elsewhere |
| SC | 3 x8 | 4 at the bottom, 3 elsewhere |

The bottom tier being one short in the first two groups IS the player's slot, which is what the
rule is built on; the uniform-count countries are ones nobody finished bootstrapping at a real
size. But the decisive objection is not the off-by-one: **a derived divisor moves whenever
somebody clicks Generate**, silently re-pricing a tier's TV money for every shipped build as a
side effect of an unrelated admin action. See `LeagueSizeResolver`.

Also note: **Scotland has 8 league rows on dev, not 4**, and every `country_profile.league_tier_count`
is 8 — so the "Scotland is a 4-tier country" assumption in the economy brief does not hold
against this database. Production unverified.

### The absolute-pence thresholds, reviewed 2026-11-12

Full report: `docs/economy-threshold-review.md`. Read it before changing any of these values —
the headline is that **`bankruptcyDeductionTiers` has two rows that cannot fire**. Rows 3, 4 and
5 all carry `deficitMax: null`, so under the first-match-wins contract the column's own docblock
states, any deficit at or above row 3's floor matches row 3 and the 20- and 50-point penalties
are dead. The table only behaves the way it reads if the app resolves by LAST match or by
highest penalty — meaning either the app or that docblock is wrong, and the fix that is correct
under every resolution is to give rows 3 and 4 real `deficitMax` values.

Separately: the whole five-rung ladder is traversed in **under half a week** of unpaid wages at
the top five tiers (0.01 weeks at tier 1). That predates the overhaul — the legacy model is
comparable — but the overhaul is what made it measurable.

`youthScholarshipWagePence` (flat £250/wk) is wrong at **both** ends of the turnover ladder, and
it took two passes to see it. Under the turnover model it became **1.97x an average tier-8
wage** — a thirteen-year-old out-earning an average first-team player — and it is **0.19% of an
average tier-1 wage**, a rounding error. A single absolute figure cannot be right across a ladder
spanning 1,047x.

**Pass 1, `Version20261112220000`:** `economyYouthScholarshipCapBp` (default 5000 = 50%) caps the
scholarship, taking tier 8 to 0.05x with tiers 1-6 byte-identical. Only ever asked "is it too
high?", so it never looked at tier 1.

**Pass 2, `Version20261112230000`:** `economyYouthScholarshipTier1Pence` + `…FloorPence`, both
defaulting to 0 (= off, the flat figure and its cap). The scholarship becomes
`clamp(anchor x averageWage(tier) / averageWage(1), floor, capBp x averageWage(tier))`:

- **One knob, not a table.** The per-tier shape is already the turnover ladder's; authoring it
  twice invites the two to disagree. Same rule as sponsorship taking the country factor but not
  the tier factor.
- **Not scaled by `S_tier`**, which looks right and is catastrophic: it carries 1.72x of spread
  against the wage ladder's 1,047x, so a £1,500 anchor puts a tier-8 scholar on £873 against an
  average professional's £127 — 6.9x worse than the defect. Modelled before anything was written.
- **The floor is load-bearing**, not a nicety: a pure proportion reaches £1.43 by tier 8, and
  free is a different mechanic from cheap.
- **Floor then cap, in that order.** The floor is a preference, the cap is the invariant, so the
  cap is applied last and an absurd floor is clamped down rather than breaking the promise.

**The cap's anchor moved from the tier's WORST wage to its AVERAGE** in pass 2. England tier 8's
cheapest professional earns £12.84/wk (ability 2 at the bottom of a 2-7 band through a 2.5
exponent), so half of it is £6.42 — the old rule produced a free scholar, not a cheap one. Safe
to redefine rather than replace: the cap is gated on `turnover`, so it has never applied to a
published figure on any box. Accepted cost: at a band as wide as tier 8's a scholarship can
exceed the single cheapest professional's wage. `£12.84/wk` is logged as its own finding in
`docs/economy-threshold-review.md` §2b.

The flat key is untouched throughout; the resolved per-tier figure is `economyTierBaseline
.tiers[].youthScholarshipPence`. `WageModelResolver::averageFirstTeamWagePence()` is the single
definition the ladder, the cap and the gate's check all share — they disagreed once and the cost
was a gate that could not be cleared.

The cap is the ONE switch in this series that ships ON (50%), and still a provable no-op: it
applies only under the `turnover` model, so the flat figure survives both the shipped state and
the half-configured period while a turnover table is being filled in. The first implementation
gated on resolvability instead of the model and capped under `legacy`, where £250 is already
sensible — `YouthScholarshipCapTest` caught it.

`app:economy:wage-table` carries all of this as check 5, so it cannot quietly stop being true.
`npcSolvencyThresholds` (weeks) and `dofWageBillThresholdPct` (a ratio) are already immune and
are the shape to copy.

### `GameConfig::$economySeasonInvestmentBasePence` (2026-11-12)

The base owner-investment ceiling, from `Version20261112210000`. **Ships at 0, which is an OFF
SWITCH rather than a neutral default**: the resolved ceiling is
`base x countryFactor x tierFactor x inflationIndex(season)`, so a base of zero makes every
amount above the ceiling and `POST /api/club/season-investment` refuses everything. That is how
both of that endpoint's new client-visible error codes ship unreachable by a client that does
not understand them — the same shape as `429 enrichment_quota_exhausted` arriving behind a cap
of 0 = unlimited.

`EconomyScaleResolver::seasonInvestmentCeilingPence()` is **the only server-side consumer of the
inflation index**. Everything else the backend publishes is base-year and the app compounds it,
but a ceiling has to be checked against the season being claimed for or a long-running save
would be held to year-one money.

**No ledger migration accompanied it.** `UserLedgerEntryType::SEASON_INVESTMENT` is a new case
on a `varchar(30)` column whose longest value was already 17 characters, and `season_investment`
is also 17 — `UserLedgerEntryTypeTest` asserts that bound. Idempotency reuses the existing
`uq_user_ledger_backend_source` partial unique index.

### Parachutes — `League::$parachute` and `GameConfig::$leagueParachute` (2026-11-12)

Two nullable json columns from `Version20261112200000`, resolved **`league ?? gameConfig`**
whole-value at every publish site. Both ship null, so every league publishes `parachute: null`
and the migration is a no-op (verified: 120 of 120 leagues).

**SERVED VERBATIM — a schema-agnostic passthrough**, exactly like `League::$trophy`,
`Player::$appearance` and `NpcClub::$identity`. The backend stores it, picks which one applies,
and publishes it without reading inside.

**That is the decision worth knowing before touching it**, because the obvious improvement is
wrong: the backend cannot pay a club anything (no income loop; the device runs the economy), so
any structure imposed here would be a second opinion about a schedule only the app can execute,
and the two would drift the first time the app wanted a fourth season or a cliff instead of a
taper. The accepted cost is that a malformed block is stored and served and only the app
notices — the same trade `starter_config.setup_tutorial_config` makes, which is why that one has
a checker command rather than a validator. `tests/Entity/LeagueParachuteTest.php` asserts the
NEGATIVE property deliberately: an arbitrary nested structure with keys the backend has never
heard of survives unchanged.

**Whole-value, not merged key by key.** Merging would require understanding the keys, which is
what the passthrough declines to do, and a half-overridden schedule is a worse answer than
either whole one. So a league override completely replaces the global — the global's extra keys
do not leak through.

Both setters coerce `[]` to null. Not validation — a disambiguation: an empty override would
resolve to "this league pays no parachute" while the global said otherwise, which is not a state
anybody authors. The visible consequence is that clearing a league's override restores the
global rather than switching parachutes off for that league.

Money in base-year pence. The agreed shape lives in `docs/api/economy-config.md`, which is the
right home for a contract the schema does not enforce.

### `GameConfig::$economyStaffRoleBandsPence` (2026-11-12)

Weekly pence band per staff role, from `Version20261112190000`. These were hardcoded
`random_int()` literals in `StaffGenerationService::build()` with a SECOND, DIFFERENT set in
`GenerateMarketDataCommand` — the two disagreed about a coach (2000-8000 against 4000-10000), so
the same role cost different money depending on which path created it. Seeded with
StaffGenerationService's values because that is the path that generates the world.

**The band does two jobs under the turnover model**, which is why it was worth lifting rather
than replacing: its MIDPOINT is the role's weight (what a chairman costs relative to a facility
manager) and its SPREAD is how ability moves pay within the role. Both were already hand-tuned.

Setter normalises each band to two non-negative integers in order and drops unknown roles — an
inverted band would make ability pay LESS the better a staff member is, and monotonicity is the
property the funding projection leans on.

### Four published-only gameplay constants on `GameConfig` (2026-11-12)

From `Version20261112170000`. **None is consumed server-side**, which is the normal shape for
this table rather than an oversight: the device is the game engine, so matchday income, NPC
insolvency and a Director of Football's opinion all happen there. They live here so an operator
can retune them without an app release.

- `matchdayFoodUptakePercent` (smallint, 35) — whole percent, clamped 0-100.
- `npcSolvencyThresholds` (json, `[4,12,26]`) — **weeks in deficit, not money**:
  `[warn, crisis, folded]`. Being weeks is what insulates it from the scaling problem the
  absolute-pence `bankruptcyDeductionTiers` has.
- `dofWageBillThresholdPct` (json, `[0.40,0.50,0.60,0.70]`) — wage-bill-to-turnover ratios as
  **fractions, not basis points**, and that inconsistency with the economy block above is
  deliberate: basis points exist there because those figures are links in an integer chain
  ending in pence, while these are only ever compared against a ratio. Converting twice would
  be the error-prone choice. `missionGemRollThresholds` is a float list for the same reason.
- `sponsorWeeklyPence` (bigint, 0) — ⚠️ **WEEKLY, where the six existing sponsor band columns
  are PER SEASON.** They are not convertible by dividing by a season length: a band is a league
  sponsor's season total rolled at pack-build time and summed into `sponsorPot`, this is a
  per-week payout on the weekly tick. 0 = no weekly sponsor income, which is the inert default.

**Both json setters sort ascending on write.** The app walks each as a ladder, so an
out-of-order row would make a later, harsher rung unreachable — the same failure mode an
unordered `bankruptcyDeductionTiers` has, since that is first-match-wins over hand-ordered rows.

### `CountryProfile` gained an economy scale (2026-11-12)

`isEconomyBaseline:bool` (default false) and `economyFactorBp:int` (default
10000 = parity), from `Version20261112160000`, which flags `EN` in `postUp()`.

- **Exactly one baseline, enforced by a partial unique index**
  (`uq_country_profile_economy_baseline`, `WHERE (is_economy_baseline = true)`)
  rather than by a service. Every country's factor is normalised against the
  baseline row's *own* value, so two baselines make the whole country × tier
  chain ambiguous — and the only symptom is money that is quietly a bit wrong
  everywhere, which nobody traces back to a flag. Partial because `false` is
  the normal state for 25 of 26 rows.
- **Normalised on read, not assumed to be 10000.** An operator may set England
  to 12000 thinking in absolutes; the baseline must still resolve to parity
  with itself or every other country shifts as a side effect.
- **No baseline flagged at all resolves every country at parity** — the inert
  state, deliberately not an exception, so a half-configured economy degrades
  to "no scaling" rather than a 500.
- `setEconomyFactorBp()` floors at 1, never 0: zero resolves every wage,
  balance and ceiling in the country to nothing, which reads as "the economy
  is off here" rather than as the typo it is.

### `InflationRateHistory` — append-only, and nothing computes from it (2026-11-12)

`ratePerSeasonBp`, `previousRatePerSeasonBp` (null only on the first row),
`changedBy` → `Admin` (nullable, **ON DELETE SET NULL** — an audit row outlives
the account that wrote it, same reasoning as `UserLedger::$sourceSyncRecord`),
`note`, `recordedAt`. Created empty by `Version20261112160200`.

**Why the rate gets a history when no other economy figure does:** changing a
tier factor re-prices what the backend *generates* from then on and the old
value stops meaning anything. The inflation rate is different in kind — the app
compounds it over a club's season count, so changing it moves every existing
save's prices retroactively, and with only the current value stored there is no
way to answer "why was that club's ceiling that" afterwards.

**The index is always derived from the CURRENT rate in `game_config`, never
replayed from this table**, and that is worth not quietly improving: a replayed
history would make a club's ceiling depend on when an operator clicked save.
`previousRatePerSeasonBp` is *stored* rather than inferred from the preceding
row, because reconstructing it from a timestamp ordering breaks the first time
two changes land in the same second.

## Misc / cache / analytics

- **`CountryWorldPackCache`** — persisted (DB-backed, not in-memory)
  cache of generated world-pack payloads. `country`, `tier`,
  `payload:array`, `generatedAt`, `payloadVersion`. No relations. See
  also `state.md`.
- **`LiveTelemetrySnapshot`** — singleton-style live-activity feed row
  (per `LiveTelemetrySnapshotRepository::getSnapshot()`) backing the landing
  page's "Boardroom Incident & Consequence Feed" widget. `fixturesSimulated`,
  `capitalDeployedPence`, `resultsWins/Draws/Losses`, `activeClubs`,
  `weeksPlayed`, `recentEvents:array` (enriched per-item shape: `time, text,
  category, categoryLabel, club, detail, amountExact, timestampIso, meta,
  trophy` — `trophy` is a TrophyConfig set only on `CUP` items; see
  `LiveTelemetryService`), `communityMoraleDelta:?int`, `totalGoals`,
  `totalMatches`, `tableTurnover`, `promotions`, `relegations`, `generatedAt`.
  `formatPenceExact()` is
  a non-compact `£31,000`-style currency formatter alongside the older
  compact `formatPence()` (£K/£M). Built up incrementally across migrations
  `Version20260915213526` → `Version20261014223020` — see `migrations.md`.
  No relations.
  **The HUD's 3rd/4th stat boxes are collective pyramid-wide totals, not
  single-club highlights.** `totalGoals`/`totalMatches` back "Goals Logged"
  and its `getGoalsPerMatchFormatted()` sub-line (always 2dp, `0.00` when no
  matches, so the fixed-width column never changes width); `tableTurnover`
  backs "Table Turnover". They replaced `dilutionEquityPercent`/
  `dilutionClubName`/`dilutionCounterparty`/`covenantActiveCount`/
  `covenantSeasonalValuePence`/`dressingRoomFalloutCount`, dropped in
  `Version20261014223020` — safe because this table is a 15-minute cache, not
  source data. Those two NARRATIVES still exist as feed categories
  (`buildDilutionEvents()`/`buildCovenantEvents()` and their `.cat-dilution`/
  `.cat-covenant` styles are all still live) — only the scalar stat boxes and
  their `buildDilutionSummary()`/`buildCovenantSummary()` helpers went.
  **`tableTurnover` reads the feed's 7-day window, not the 24h stats window**:
  a promotion/relegation only exists once a season concludes, far rarer than a
  sync, so a 24h count would read 0 almost permanently.
  `promotions`/`relegations` (added `Version20261102160000`) are that same figure
  split by direction over that same window — the tile now reads "▲ 7 ▼ 4". They are
  counted by `SeasonRecordRepository::countTurnoverByDirectionSince()`, which counts
  each direction rather than rows, so a row flagged BOTH promoted and relegated adds
  one to each and the two can sum above `tableTurnover`'s row count. `tableTurnover`
  **stays and is still written every refresh**: `GET /api/telemetry` publishes it as
  `stats.tableTurnover` for app releases already in the wild. The migration backfills
  `promotions` from it rather than leaving 0/0 for one cron interval.

## Translations

- **`Language`** (table `language`) — admin-configured language. `code`
  (2-letter, unique, treated immutable once shipped), `name`, `isEnabled`,
  `isDefault` (exactly one at a time — enforced in `LanguageCrudController`,
  not a DB constraint), `sortOrder`. No relations.
- **`TranslationKey`** (table `translation_key`) — one translatable
  string. `key` (unique, human-readable; for narrative-linked rows this is
  a derived label only, never parsed). `entityType`/`entitySlug`/
  `fieldName` (all `?string`, nullable) — `null`/`null`/`null` = generic
  UI-copy key; all three set = linked to one field of a `GameEventTemplate`/
  `FacilityTemplate`/`Excursion`/`PlayerArchetype` row (explicit typed
  link, not a naming convention). `isPluralSensitive:bool`, `bandedReferences:?array (json)` —
  structural metadata about the key itself (which client-side interpolation
  handling it needs), identical across every language, so it lives here
  rather than per-language. `UNIQUE(key)` and a plain `UNIQUE(entityType,
  entitySlug, fieldName)` — the latter works because Postgres treats
  `NULL`s as distinct under a plain unique index, so generic rows (all
  three `NULL`) never collide with each other. `OneToMany` → `translations`
  (cascade persist/remove, `orphanRemoval: true`) — see
  `services.md`/CLAUDE.md's "Languages & Translations" for why this
  collection must be queried directly (`TranslationRepository::
  findAllForKey()`) rather than read off an in-memory `TranslationKey`
  instance, which can silently appear empty for a row created earlier in
  the same request.
- **`Translation`** (table `translation`) — one language's value for one
  `TranslationKey`. `value:text` (empty string is a deliberate value;
  "untranslated" is the absence of a row). `ManyToOne` → `translationKey`,
  `language` (both not nullable, `CASCADE`). `UNIQUE(translationKey,
  language)`.
- **No default-language (EN) `Translation` row exists for narrative
  content** — `GameEventTemplate`/`FacilityTemplate`/`Excursion`/
  `PlayerArchetype`'s own `title`/`bodyTemplate`/`label`/`description`/
  `body`/`name` fields are the EN value, read live. Only
  non-default-language overrides are ever stored.

## Not an entity

`src/Entity/Concern/EditableJsonColumnTrait.php` — a plain PHP trait
(not `#[ORM\Entity]`), providing `decodeJsonInput`/`invalidJsonInputFor`
helpers for entities with admin-editable JSON columns.
