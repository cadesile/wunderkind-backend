# Service-Layer Responsibilities

`src/Service/` — responsibilities drawn from each file's imports/
constructor and, where present, its class docblock — not guessed from
filename alone.

- **`AccountDeletionService`** — permanently deletes a user account and
  every club they own, including cascade-uncovered data, in one
  transaction. `BLOCKING_CLUB_DEPENDENTS` is the hand-maintained list of
  club dependents whose FK is NO-ACTION at the DB level and so must be
  deleted before their club; **a new entity with a non-nullable `club` FK
  must be added here and to `DeleteAdminController::cleanClubForeignKeys()`
  (the admin's own delete path), or both deletes raise
  `ForeignKeyConstraintViolationException`** — as `UserLedger` did in
  production. `AccountDeletionServiceTest` builds one row per dependent, so
  the regression is only caught for entities actually present in that
  fixture.
  **`deleteAccountsBulk(list<User>)` is the set-based sibling** — same tables,
  same order, same `BLOCKING_CLUB_DEPENDENTS` const, but one statement per
  table per chunk (200 accounts) instead of per account, and the leaderboard
  cache invalidated once at the end rather than per account. It exists because
  the per-account path opens a transaction and invalidates every leaderboard
  category each time: clearing the 3,604 spoof accounts a test database had
  accumulated did not finish inside 115s that way, versus **17.7s** in bulk.
  Use it for any purge; `deleteAccount()` stays the path for a single GDPR
  erasure. Its one caller today is `app:clubs:purge-spoof`.
  **`releaseUnstartedCompetitionEntries()` runs on both paths, and is a deletion that is
  deliberately NARROW.** `competition_entrant.club_id` became ON DELETE SET NULL on
  2026-11-02 so an entry outlives its club and finished brackets keep naming it (see
  `03_data/output/migrations.md`). That is right for a competition with a bracket and wrong
  for one still REGISTERING: an orphan there would hold its capacity slot for ever, and
  `lockIfComplete()` waits on every entrant having a stored squad, so the competition could
  never fill or lock again. So entries in a REGISTERING competition are released and
  everything from SCHEDULED onwards is kept — the same split as the `FriendlyChallenge` line
  beside it, where an unaccepted challenge goes and a played one stays. No refund: the
  account being deleted has no balance left to pay into.
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
- **`ClubResolver`** — resolves *which* club a request is about, for
  accounts that own more than one (one club per local save/game). Live
  requests (`InboxController`, `CompetitionController`, `ClubController`,
  `FinanceController`, `LeagueController`, `MarketController`,
  `AdminMessageController`, `InitializeController`) call
  `resolveFromRequest()`, which reads the **`X-Club-Id` header** — the
  client sets it from whichever save is currently loaded. Deferred/queued
  payloads that are built on-device and may be delivered long after the
  save has switched (`SyncRequest`, `ConcludeSeasonRequest`) instead carry
  a `clubId` field in the request **body**, resolved via `resolve($user,
  $clubId)`. Either path throws `ClubMismatchException` (→ 4xx) if the id
  names a club the caller doesn't own. Omitting the id — the only
  behavior pre-dating this mechanism — falls back to
  `ClubRepository::findByUser()`, i.e. a guess at "the newest club",
  kept only so older, unpatched clients keep working. Not documented in
  `docs/api/` as of 2026-09-24; this entry is the source of truth for the
  header name/contract until a proper API doc exists.
- **`CommunityStatsService`** — computes community-wide stats (most
  transfers/wins/development/seasons/trophies) from match/transfer/season
  repositories. Every ranked row is decorated with the club's
  `country`/`homeKitConfig`/`awayKitConfig`/`badgeConfig` via one
  `ClubRepository::findByIdsKeyed()` lookup over the already-ranked ids — the
  same identity block `LeaderboardItemDto` emits, so one client-side renderer
  serves both boards.
  `getMostDevelopment()` ranks `Transfer::$developmentPoints`, **a field nothing
  writes** (`TransferSyncDto` has no such member; the only writer is
  `app:seed-community-stats-fixtures`), so it returns an all-zero column in
  production. Kept as a published endpoint; the landing page shows
  `getMostWins()` in its place.
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
  serialize concurrent last-slot registrations. Also owns the static
  snapshot club/lineup normalizers (`normalizeSnapshotClub()`,
  `normalizeClubData()`, `formatLineupForClient()`,
  `formatSnapshotXiForClient()`) shared by competition
  registration/resubmit, `CompetitionResultsService`, `CompetitionResult::toClientSummary()`,
  `MatchQueryService`, `CompetitionController`, and `FriendlyChallengeService`
  so the client's `TournamentSnapshotBuilder` payload (`club.identity` with
  resolved `home`/`away` kit configs + 7 badge keys, `club.kitHomeConfig` /
  `kitAwayConfig` / `badgeConfig`, `club.stadium`, `club.stadiumName`, and
  `players[].appearance`) is preserved and returned consistently across all
  entrant, match summary/detail, friendly challenge, and result payloads
  (preferring `identity.badgeShape` / `badgeConfig.badgeShape` over the
  client's hardcoded root `club.badgeShape = "shield"` legacy placeholder,
  explicitly validating `playingStyle` against `PlayingStyle` enum, and
  omitting `stadiumName` and `playingStyle` rather than emitting `null`
  when unset per `wunderkind-app/docs/api/match-detail-club-fields.md`).
  **`formatSnapshotXiForClient()` backs the pre-match lineup**: an unplayed
  cup fixture or accepted friendly returns both clubs' registered snapshot
  XIs as `homeLineup`/`awayLineup` instead of two empty arrays, projected
  onto the exact key set a played lineup carries (`name`, `position`,
  `appearance`, `goals`, `assists`, `yellowCards`, `redCards`, `rating`) with
  counters at `0` and `rating` **null** — a played rating runs 1.0-10.0, so
  `0` would render as the worst performance ever rather than "not played".
  A projection rather than a pass-through because `formatLineupForClient()`
  only backfills `appearance` and strips `id`, so raw snapshot players would
  leak the snapshot's wider shape (`currentAbility`, `dateOfBirth`, …) and
  carry none of the per-match keys. Submitted order is preserved, unlike a
  played lineup, which the engine sorts by ability. **Both clubs' XIs are
  visible pre-match by explicit product decision** — note this is live
  information, not merely stale, and **the draw does not freeze it**: a round
  resolves against whatever snapshot the entrant holds at resolve time
  (`DeterministicEngine::playersOf()` reads the side built from the entrant's live
  `competition_entrant_squad` row), and
  `resubmit` stays open while any round is `DRAW_PENDING`. Since every round
  but the last has a later `DRAW_PENDING` round behind it, resubmit remains
  open through that round's draw and up to its resolution — a club can change
  the XI that plays an already-drawn fixture after seeing its opponent's. Only
  the final locks at its own draw, nothing being `DRAW_PENDING` behind it. So
  there is no "drawn and locked" state to gate on for a non-final round; the
  alternatives are a real squad-lock concept or a viewer-scoped payload (own XI
  only until the result exists). Closes
  `wunderkind-app/docs/api/match-detail-club-fields.md` §4a; its §4 is the product
  question this answers.
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
- **`CompetitionRegistrationService` also owns the split**, as of the snapshot
  restructure (see `03_data/output/schema.md`'s "The split rows"):
  `clubDisplayBlock()` picks the bounded display key set,
  `clubEngineBlock()` lifts out the three keys only the match engine reads
  (`CLUB_ENGINE_KEYS`), `splitSnapshot()` is the one place deciding what lands
  where, and `snapshotEnvelopeFrom()` reassembles the old envelope for the two
  callers that genuinely want it (spoof cloning, the admin viewer).
  **`splitSnapshot()` is public and serves both domains** — `FriendlyChallengeService`
  calls it from `postChallenge()`/`acceptChallenge()` and reads `ovr` back out of the
  result, because `FriendlyChallenge` stores that as a matchmaking column of its own.
  It had its own copy until 2026-10-28, and the copies had drifted: the friendly one
  recomputed OVR from the XI unconditionally, so a device-supplied `club.ovr` was
  honoured on a competition registration and discarded on a friendly post of the
  identical envelope. The shared rule is: a numeric `club.ovr` wins, else derive from
  the XI.
  `normalizeClubData()` itself is untouched — the pickers front it — which is
  why `FriendlyChallengeService` keeps using it exactly as before.
  `repointClubData()` moves **both** `id` and `clubId` when a snapshot is
  re-pointed at a different club: the normaliser resolves identity as
  `clubId ?? id ?? fallback`, so setting only one leaves the stale other
  winning and a spoof clone keeps pointing at the club it was copied from.
- **`Competition/CompetitionResponseBuilder`** — builds the body of
  `GET /api/competitions/{id}`. Lifted out of `CompetitionController` because
  the cron passes that change a bracket need to prebuild the document and
  cannot reach a controller action, and because `ClubHubController` was
  injecting `CompetitionController` purely to reuse
  `serializeInstanceSummary()`. `sharedDocument()` returns the
  viewer-independent part (carrying `viewer => null`, so the request path
  *overwrites* rather than appends and key order does not depend on a cache
  hit); `viewerBlock()` composes the one key that varies per caller;
  `stateFingerprint()` is the freshness key and the ETag body.
  **`DOCUMENT_SCHEMA_VERSION` is a component of that fingerprint, and must be bumped in the
  same commit as any change to the document's shape.** Adding a key is not a state change,
  so nothing else in the fingerprint moves: every stored `competition_response_cache` row and
  every client ETag would keep validating, and a COMPLETED competition's state never changes
  again, so its clients would be served the old shape indefinitely. (Currently `3` —
  `rounds[].fixtures[].winnerSide` added and the `home`/`away` fallback below; `2` was
  `rounds[].roundId`, `null` on a `future` placeholder, see
  `docs/api/competition-round-id.md`.)
  **`serializeFixtureSide()` is why a finished bracket still names both clubs.** A fixture's
  side comes from the entrant row when it is there and from `competition_result`'s frozen
  `homeClubJson`/`awayClubJson` when it is not — the same block, since
  `CompetitionResultsService::clubJsonOf()` writes it straight from
  `getClubDisplayJson()`. It exists because deleting a club used to blank it out of every
  bracket it had played in (see `03_data/output/migrations.md`'s 2026-11-02 entry for the
  full mechanism and the deploy-time purge that triggered it); the entrant row now survives a
  club deletion, and this repairs the brackets damaged before it did. Such a side carries
  `entrantId: null` and `seed: null` — genuinely gone — so **a client must display a side
  from `home`/`away`, never by resolving `homeEntrantId` against `entrants[]`**.
  `winnerSide` exists for the same reason: `winnerEntrantId` is unrecoverable on a repaired
  fixture, so `winnerSideOf()` derives the winner from the stored score, penalties first.
  Pinned by `tests/Service/Competition/BracketSurvivesClubDeletionTest.php`.
  The ETag formats the fingerprint with `%s`: it was
  `%d`, which parsed only the hex hash's leading decimal run, so 42% of fingerprints
  collapsed to `0` and about one real state change in five did not move the ETag at all —
  a polling client got a 304 with the previous bracket behind it.
- **`Competition/CompetitionResponseCacheService`** — `getOrBuild()` /
  `rebuild()` / `invalidate()` over `competition_response_cache`, with a
  Postgres advisory lock so a cold competition under load builds once. Shaped
  after `WorldPackCacheService`, and database-backed for the same reason: see
  that entity in `03_data/output/entities.md`. Prewarmed by
  `WarmCompetitionResponseMessage`, dispatched by the draw and resolve message handlers
  once their work has committed — **never inline in a cron tick**. That distinction is
  load-bearing: a tick holds the round's entrants in its identity map and Doctrine will
  not re-hydrate them, so building the document there while fingerprinting fresh SQL
  stored a stale bracket under a fresh key. The worker clears the EntityManager between
  messages, which is what makes the warm handler read clean. Warm failures are swallowed:
  the cache self-validates, so a miss means the next reader rebuilds.
  **Builds are logged; hits are not.** `buildAndStore()` is the single place a document is
  written and emits `competition.cache.build` at info with `elapsedMs` and `bytes` — the
  expensive, rare event. Payload size in prod is the one signal that would catch per-fixture
  match detail being re-embedded in a release. Hit *rate* is deliberately not counted here: it
  is a ratio over requests, so it is `1 - (builds / GET /api/competitions/{id} requests)` with
  requests from the access log, rather than a log line on the hottest read path in the domain.
- **`Competition/CompetitionHealthService`** — one read-only snapshot of whether round
  processing is keeping up: `competition` queue depth and consumer lag from the oldest
  undrained message, round claims held vs past the 300s lease, rounds due and unclaimed past a
  180s grace (three cron ticks), and response-cache coverage. All DBAL, read-only, no locks —
  safe to run against production while rounds resolve, and it can never be the reason one
  fails. Exposed as `app:competition:health` (`--json`, `--strict`) and scheduled every 5
  minutes by `docker/competition-health.sh`.
  It reports **state**, never counters, so there is no metrics store to drift. Two details
  worth keeping: an absent `messenger_messages` is reported rather than read as an empty queue
  (the Doctrine transport creates it lazily, so on a fresh database it does not exist — and a
  health check must not be the thing that fails); and a *claimed* round is never counted
  overdue however late, since it is being worked on and double-reporting it would send an
  operator chasing a backlog that is one round in progress.
- **`Competition/MatchEngineSideFactory`** — builds `MatchEngineSide` inputs
  from whatever a domain stores. It lives in `Service/Competition`, not beside
  the value object, precisely so the engines import no entities.
- **Round processing is queued.** `CompetitionDrawService::dispatchDueDraws()` and
  `CompetitionResultsService::dispatchDueResults()` are what the cron calls: one
  `DrawCompetitionRoundMessage`/`ResolveCompetitionRoundMessage` per due round onto the
  **`competition`** transport (its own, because a round is latency-sensitive against
  wall-clock time and must not queue behind a pool top-up or a world-pack regenerate),
  drained by `docker/competition-consume.sh`. The handlers take the claim themselves, so a
  duplicate dispatch is a no-op and the dispatcher needs no dedup guard — a deliberate
  divergence from `StarterPoolAuditService`, whose queue depth genuinely stacks.
  `drawRoundIfClaimable()`/`publishResultsIfClaimable()` are the shared per-round
  implementation; `drawDueRounds()`/`publishDueResults()` remain as synchronous drivers over
  them for tests and as a fallback.
- **Round claims are leases** (both `CompetitionDrawService` and
  `CompetitionResultsService`). `drawLockedAt`/`resolveLockedAt` are reclaimable after
  `CompetitionRound::CLAIM_LEASE_SECONDS` and released on success in the same flush as the
  terminal status, so `*_locked_at IS NOT NULL AND status = <pre-work status>` means "in
  progress, or a tick died mid-round". They were one-way flags, which made any mid-round
  exception strand the round permanently. Relatedly, `claimDraw()` no longer sets
  `status = DRAWN` — that used to commit before `drawRound()` created any fixture, and a
  failed flush then orphaned the round from both due-round queries at once.
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
  drawing it — that's a later `CompetitionDrawService` tick. **The final
  is the exception: its `scheduledAt` is the publishing instant, not
  `+ intermissionSeconds`**, so the next draw tick (≤60s) picks it up.
  Once the semi-final resolves there are exactly two entrants and one
  possible fixture, so that draw can reveal nothing and an intermission
  before it is dead time for the two finalists. It is still the draw
  service that draws it — `scheduleNextRoundDraw()` only moves the clock,
  so the draw stays behind its own atomic claim-lock, there is no
  results↔draw service cycle, and the final keeps the normal fixture
  seeding/inbox/push path (its existing "FINAL draw: X vs Y" message to
  both finalists is unchanged). For the
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
  **A CLOSED period is read-only**: `getLeaderboard()`'s cold callback checks
  `LeaderboardPeriodArchiveRepository::findClosed()` first and projects the frozen archive
  entries, and `recalculate()` returns 0 early. Not an optimisation — the close job prunes
  a closed period's live rows while this method's cold path *writes*
  (`computeAggregateScores()` will upsert rows for any period string), so without the guard
  one public GET would resurrect a whole pruned board as junk rows. A `pending` archive
  deliberately does **not** make a period read-only: the close is in flight or failed
  part-way, the live rows are unpruned, and the live board is still the truthful answer.
- **`App\Service\Leaderboard\LeaderboardPeriod`** — the one place a period key is
  produced, parsed, labelled or walked. All-static and dependency-free (the
  `TrophyConfigFactory`/`CountryContentRegistry` precedent), because entities, commands and
  services all need it and an entity cannot be injected. Weekly `2026-W44`, monthly
  `2026-M10`, quarterly `2026-Q4`; the weekly key is **byte-identical** to the ISO-week key
  the game has always written, pinned by `LeaderboardPeriodTest` against the raw
  `format('o-\WW')` it replaced at four call sites. Zero-padding is load-bearing — the close
  job's monotonic anchor compares keys as strings. `cadenceOf()` round-trips through
  `boundsOf()` rather than only pattern-matching, which is what rejects a well-formed key
  naming a period that does not exist (`2025-W53` in a 52-week ISO year; `setISODate()`
  would silently roll it into 2026-W01).
  Deliberately **not** `App\Enum\StatsPeriod`/`PeriodResolver` — that is a *rolling-window*
  vocabulary for `/api/stats/*` where `month` means "-30 days".
- **`App\Service\Leaderboard\ActivePeriodService`** — which periods get
  `leaderboard_entry` rows written right now: `'all-time'` and the current ISO week always,
  plus the current key of every **enabled** `LeaderboardAwardDefinition`. Asked by
  `SyncService` and `app:leaderboards:generate` instead of each hardcoding a list. The
  gating is not thrift: while a definition is disabled its key is not emitted, so no rows
  exist for it, so a later re-enable measures growth from the re-enable and **cannot
  retro-award the all-time leader**. Memoised per instance.
- **`App\Service\Leaderboard\LeaderboardPeriodCloseService`** — closes an ended period:
  claim, final score and rank, freeze the board, award each place, one flush, then prune.
  Driven by `app:leaderboards:close-periods` (cron, every 10 min at :04) and by the admin's
  **Close now** panel. Runs **inline, with no Messenger transport** — per definition the work
  is one indexed read, ≤100 inserts and ≤N messages, and the due count is bounded by the
  enabled definitions, nothing like the 32-fixture round that justified the `competition`
  transport. Three things are load-bearing and easy to undo by accident:
  the claim is **raw DBAL** `ON CONFLICT DO NOTHING`, auto-committed on its own outside any
  wrapping transaction (a unique violation out of `flush()` closes the shared EntityManager
  and would strand every remaining definition in the tick; and the claim must survive a
  later failure rather than silently re-opening the period); the archive's terminal status
  and the definition's anchor are set **on the entity, in the same flush as the work they
  describe**, per "never commit a status ahead of the work"; and the prune runs **only after
  that flush has committed** (the `*_squad` precedent — the other way round, a failed flush
  clears the board with nothing archived). Due-ness is two parts, both required: catch-up
  from the anchor **oldest first, one period per pass** so a cron outage settles missed
  periods in order, *and* the chosen period must actually have ended, so clock skew or a
  stray `--force` cannot close an open one.
- **`App\Service\Leaderboard\LeaderboardAwardDeliveryService`** — turns a frozen archive
  row into a claimable prize. **No new claim mechanism, no new apply path, no new client
  endpoint**: an award becomes an ordinary `InboxMessage` whose top-level
  `offerData.effects` the existing accept path applies, so `GET /api/inbox` and
  `POST /api/inbox/{id}/accept` are untouched. `senderType` is **`COMPETITION`, and must
  stay so** — `InboxService::acceptMessage()` routes only `COMPETITION`/`FRIENDLY` to the
  applier, so a `SYSTEM` message carrying effects renders `requiresAccept: true` and then
  applies nothing, silently. (And no new `MessageSenderType` case: the cost is contract, not
  schema. The discriminator is `offerData.type = 'LEADERBOARD_AWARD'`.) Never mutates
  `Club` — `SyncService` does an absolute `setBalance()` from the client, so a server-side
  credit on the cron's schedule would just be overwritten. **Writes no `UserLedger` row**:
  that ledger's before/after chain is a club→owner dividend record, and inserting a club
  prize would corrupt `getCurrentBalance()` and the Rich List. Pushes go out after the
  commit, `type = 'LEADERBOARD_AWARD'` (uppercase, matching `ROUND_DRAWN`/`MATCH_RESULT`).
- **`App\Service\Leaderboard\PeriodAwardsBoardService`** — the landing page's
  `#period-awards` rows, ready to render. **No snapshot entity and no cache**, deliberately:
  `ClubSpotlight` is a snapshot row because its source is an aggregate over sync payloads,
  and `OwnerSpotlightService` is live-behind-a-short-cache because a snapshot would mean a
  migration and a cron entry to forget — but here the **archive already IS** the
  cron-written denormalised row, carrying frozen club name, kit, badge and country, and it
  never changes once written. Two indexed reads, with the entry fetch **batched**
  (`findTopEntriesForArchives()`) for the same reason `findByEntrants()`/`findBothSides()`
  exist: don't trade a snapshot for an N+1 of the same size.
- **`App\Service\Competition\HallOfFameService`** — sole owner of the two "who won"
  keys on `GET /api/competitions/champions` AND `GET /api/club/hub`, which each used to
  build their own. `currentHolders()` is one row per **active template** (its current
  holder, via `findLatestCompletedForTemplate()`); `history()` is one row per **completed
  instance**, newest first, offset-paginated (`findCompletedHistory()`, `DEFAULT_HISTORY_LIMIT`
  = 20, `MAX_HISTORY_LIMIT` = 50 clamped **in the service** because the route is public);
  `hubHistory()` is the first `HUB_HISTORY_LIMIT` = 5 of that, matching what the landing
  page's board shows. Both public readers end in one private `row()` — the anti-drift
  guarantee, and it closed drift that had already happened: the two controllers' copies read
  the trophy through different paths (`normalizeTrophy()` vs a `serializeInstanceSummary()`
  result). **Why `history()` exists at all:** `champions[]` was being read as the historical
  winners list and cannot be one — its length is the number of active templates, so one
  template with five finals settled returns one row permanently, which is exactly what the
  app was reporting. `history()` asks the landing board's question instead (recent finals,
  whatever template) and, unlike `findFiltered()`, ignores `template.isActive` so a retired
  cup keeps its champions. Query budget: `history()` is **2 queries regardless of page
  size** — the page, then `CompetitionFixtureRepository::findFinalFixturesForCompetitions()`
  for every final at once — feeding `CompetitionResponseBuilder::championPair()`, which runs
  none. Reusing `serializeInstanceSummary()` per row would have been roughly six queries per
  row for the two keys a row keeps. Contract: `docs/api/competition-champions-history.md`.
- **`App\Service\Competition\CupResultsBoardService`** — the landing page's
  `#cup-results` board: the latest completed knockout cup in full (frozen winner and
  runner-up, road to the final, four caption figures) plus up to `EARLIER_LIMIT` = 4
  earlier finals. Club identity is read **only** from `CompetitionResult::$homeClubJson`
  / `$awayClubJson`, copied at the final whistle — never off the live `Club` — so a
  purged or rebranded club still renders as it was on the day, the same rule
  `PeriodAwardsBoardService` follows. No cache and no snapshot row, for that service's
  reasons. Returns `null` until a cup has a resolved final, which hides both the section
  and its nav link. Reads through
  `ActiveCompetitionRepository::findRecentlyCompletedWithKnownCompletion()` rather than
  `findRecentlyCompleted()`: Postgres orders `completed_at DESC` **NULLS FIRST**, and
  `status`/`completedAt` are independent fields on the admin form, so the looser query
  would present an undated instance as the latest cup final.
  `resolveOwner()`/`resolveManager()` are **stubs returning `null`** — owner and manager
  are not in the frozen display block, so both figures render empty; filling them means
  either wiring the Owner Identity source `OwnerSpotlightService` uses plus the manager
  `Staff` row, or adding those keys to
  `CompetitionRegistrationService::clubDisplayBlock()` so they freeze like everything else.
- **`LeagueImportExportService`** — imports/exports league/NPC-club
  config (formations, reputation tiers, the league `trophy` sprite config,
  city sizes, kit+badge `identity`). On import, a row carrying the retired
  `trophyImage`/`trophyColour` pair instead of `trophy` is converted through
  `TrophyConfigFactory::fromLegacy()`, so a world backup committed before the
  trophy component changed still restores its trophies.
- **`LeagueService`** — league lifecycle logic (season conclusion,
  leaderboard categories), using `LeagueRepository`,
  `GameConfigRepository`. `generateLeaguesForCountry()` reads its per-tier
  financial/trophy/sponsor defaults from `GameConfig::getLeagueTierDefaultsForTier()`
  (DB-backed, shared across every country) rather than a hardcoded const —
  editable via the admin "League Tier Defaults" bulk-edit screen
  (`admin_leagues_overview` → `LeagueAdminController::bulkEditTierDefaults()`,
  route `admin_league_tier_defaults_bulk_edit`), which can also cascade the
  submitted values onto selected already-existing `League` rows.
  **`concludeSeason()` is idempotent per club + season for a RETRY, and
  overwrites for a CORRECTION** — the client queues
  and retries that POST, and a cancelled request can never be proven not to
  have reached the server. Three guards: `UNIQUE(club, season)` on both
  archive tables; the first call's response body stored on
  `SeasonSnapshot::$responseJson` and replayed verbatim (*not* recomputed —
  concluding re-rolls sponsors and regenerates fixtures, so a recomputed reply
  would describe a different world, and would re-roll for every other player
  in that country); and `currentSeason` advancing only when the season being
  concluded *is* the current one, so a late retry cannot skip a season
  forward. The season comes from the new optional `ConcludeSeasonRequest::$season`,
  falling back to `Club::$currentSeason` for older clients — keying on
  "current season" alone is what made a *late* retry unsafe.
  `currentSeason` moves to `max(current, season + 1)`, so a payload for a season
  *ahead* of the server's count is archived and pulls the count up rather than
  being rejected. That is deliberate: the client's outbox **drops** a 4xx instead
  of retrying, so a 422 there would silently lose that season and cascade into
  every later one queued behind it. The only 422 left is a season above 1000
  (`SMALLINT` guard — an out-of-range value would be a 500, which the outbox
  retries forever).
  **A resubmission carrying different figures is a correction, not a retry.**
  `SeasonRecord::matchesResult()` compares the ten result fields
  (`finalPosition, gamesPlayed, wins, draws, losses, goalsFor, goalsAgainst,
  points, promoted, relegated`) and nothing else — the archival blobs are
  excluded because the retry queue resends a 52KB `seasonFixtures` array and a
  reordering of it is a retry, so comparing them would log a correction on every
  flaky connection. Identical figures still write nothing. Differing ones go to
  `overwriteConcludedSeason()`, which rewrites the `SeasonRecord`'s result (via
  `SeasonRecord::overwriteWith()`, which bumps `overwriteCount` and files the
  superseded position and W/D/L on `overwriteAudit` in the same call, since
  either alone is a lie about the other) and re-archives
  `season_snapshot.snapshot_data` from the new payload — the latter through the
  shared `buildSnapshotData()`, passed `SeasonRecord::getLeague()` rather than
  the club's current league, because the club has already moved on.

  **That overwrite is deliberately narrow, and the narrowness is the design.**
  It touches those two rows and nothing else: not the club's league, not
  `currentSeason`, not league sponsors, not fixtures, not
  `Club::$hallOfFamePoints`. The client owns the league-standing transition now
  and this payload is purely backend data, so re-running `resolveNewLeague()`
  here would read the club's *already moved* league and promote it a second
  tier. The derived Hall of Fame score is the accepted cost: correcting a season
  into or out of first place leaves the stored mirror stale until the club's
  next conclude or the next `app:leaderboards:generate`, and a dispute is
  settled by hand from `overwriteAudit` — which is what it is for. Both writes
  are raw SQL in one transaction and there is **no `flush()` on that path at
  all**, which is also why the unpersisted-`LeagueSponsor` hazard below cannot
  arise there; `replayConcludedSeason()` may roll sponsors, so it runs after the
  overwrite returns.

  `storeConcludeResponse()` writes with raw SQL rather than a flush on purpose:
  `rollLeagueSponsors()` has just mutated managed `LeagueSponsor` rows and
  those re-rolls are deliberately never persisted (they only appear in the
  reply), so a flush there would start committing them as a side effect.
  Measured cost: ~65 ms for a full eight-tier pyramid, ~5 ms for a replay.
- **`TrophyConfigFactory`** — the one place a trophy config is defaulted,
  validated and migrated. All-static and dependency-free (following
  `CountryContentRegistry`), because `GameConfig` normalises its own
  league-tier-defaults rows through it and an entity cannot be handed an
  injected service. `validate()`/`validateNullable()` keep known keys, check
  the `TrophyStyle`/`TrophyFinish` enums, accept only `#rrggbb` colours, fall
  back per key and never throw — they sit in front of admin form posts
  (`TrophyConfigType`, `LeagueAdminController`), the world import, and the
  schema migration's backfill. `fromLegacy()` holds the `trophy-N` →
  style + `gold_silver` → `twotone` mapping. Deliberately has **no**
  `random()`: league and competition trophies are admin-chosen or come from
  the tier-defaults table, so nothing server-side rolls one — the admin
  widget's Randomise is `window.randomTrophyConfig()` from the generated
  compositor bundle. See CLAUDE.md's "Trophy Identity" section.
- **`App\Service\CountryContent\CountryContentRegistry`** — single canonical
  per-country content store (place/population/region/capital data, club-name
  suffixes, stadium formats, `WorldRegion`, `MarketPoolService` business
  cluster), keyed by `App\Enum\Country`. Replaced four previously-scattered,
  independently-drifting consts. See
  `docs/world-generation/adding-a-country.md`.
  Its `WORLD_REGIONS` table is the **only** demonym→skin-tone route: every
  nationality resolves `Country::fromNationality()` → `worldRegion()` →
  `WorldRegion::skinWeights()`, and anything unresolved falls back to a
  *uniform* roll across all six `SkinId`s rather than a default region. A
  `Country` with no entry here therefore silently loses its skin weighting, and
  a `WorldRegion` with no country pointing at it can never be selected at all —
  `EASTERN_EUROPE` sat orphaned in exactly that state until `PL` was added.
  `WorldRegionTest` now asserts both directions.
- **`OwnerSpotlightService`** — the landing page's "Owner Spotlight" (richest
  owners + their clubs and divisions, under Chapter 01 of the Journey section).
  Deliberately **not** modelled like `ClubSpotlight`/`LiveTelemetrySnapshot`: it
  is two indexed queries over club/user, so it is computed live behind the same
  short-lived cache `WorldOverviewService` uses — no entity, migration or cron
  entry. Ranks on **`UserLedgerRepository::findTopOwnersByBalance()`** — each owner's
  latest `balanceAfterPence`, i.e. money actually drawn — not on summed club
  balance and not on the deprecated `Club::$totalCareerEarnings`. Owners with no
  ledger entries are absent rather than listed at zero, so the panel is sparse
  (production held **one** ledger entry across 162 owners at the time of the
  switch) and fills in as clients report `dividend_draw`. See
  `docs/api/user-ledger.md`.
- **`ReputationDecayService`** — pure arithmetic for dormancy-based reputation
  decay (5 points per whole day a club doesn't sync); `app:reputation:decay`
  owns iteration and persistence. Decay is **transient by design** — reputation
  is client-authoritative and the next sync restores it. Full rationale,
  including why there is no separate backfill command and why the command
  writes through to the non-aggregate `club_reputation` leaderboard entries
  itself, is in `docs/reputation-decay.md`.
- **`LiveTelemetryService`** — feeds the landing page's live activity feed
  (server-rendered Twig via `LandingController`) and, since 2026-10-21, the public
  `GET /api/telemetry` endpoint the React Native app reads (`Api\TelemetryController`,
  contract in `docs/api/live-telemetry.md`). **Two consumers now** — a change to the
  snapshot or feed-item shape affects both, and the API projection strips three stored
  keys (`at`, `time`, `dossier`) that must never reach a client. **Every counter and every feed line is backed by real data.** The
  earlier six static sackings/boardroom-clash/contract-dispute/youth-intake lines
  were illustrative filler with no backing data and were **removed by product
  decision**; "no illustrative filler" is now a binding constraint on every
  category added (see the service's class docblock and
  `templates/landing/sections/_chairmans_terminal.html.twig`). Do not describe
  this feed as containing placeholder entries. One genuine caveat remains:
  `weeksPlayed` is an approximation (sync count x `WEEKS_PER_SYNC` = 4), not a
  true weeks-elapsed count. `tableTurnover` also reads the 7-day feed window, not
  the 24h stats window the HUD header advertises — deliberate, since
  promotions/relegations are far rarer than syncs. Since 2026-11-02 the HUD renders
  that figure as TWO, `promotions` (▲) and `relegations` (▼), counted by
  `SeasonRecordRepository::countTurnoverByDirectionSince()` over the SAME 7-day
  window; `tableTurnover` is still written because `GET /api/telemetry` publishes it.
  The window did NOT move to 24h with the split — measured on the SeasonRecord
  history to hand, movement lands on 33 of 55 days, so 24h would read ▲0 ▼0 about
  two days in five, which is twice as visible as one 0 was.
- **`App\Service\Competition\CupTelemetryService`** — the CUPS band inside the same
  terminal (`cups`, passed by `LandingController`), plus the rows behind the feed's
  `CUP // WINNER` lines. **Read LIVE, not from the snapshot**, unlike everything else
  in that section: the band's countdowns are only honest if the round times are
  current, and a 15-minute-old "RESULTS IN" is 15 minutes wrong. Bounded by
  `LIVE_LIMIT`/`OPEN_LIMIT`/`WINNER_LIMIT`; a short cache would still be safe, since
  the JS counts down client-side. Winners come from the frozen
  `CompetitionResult::$homeClubJson`/`$awayClubJson`, never the live `Club` — same
  rule as `CupResultsBoardService`, which is why a cup won by a purged club still
  renders. Returns `null` when nothing is live, open or ever won, which drops the band
  entirely. Timer semantics: a `DRAWN` round counts down to `matchesResolveAt`
  ("RESULTS IN"), a `DRAW_PENDING` one to `scheduledAt` ("DRAW IN"); `matchesResolveAt`
  is nullable, and both the band's sort and its `nextResultAt` exclude a null rather
  than let it compare below every real date and read as the most imminent of all.
  `aggregate()` also produces `totalGoals`/`totalMatches` for the HUD's
  "Goals Logged" box, summed from `fixtures[]` and **deduplicated by
  `fixtureId`** — that array carries the syncing club's WHOLE division's
  round (see `attachClubDossiers()`), so every player-owned club in a
  division reports the same fixtures and a club syncing twice resends them.
  Measured on real data: 860 raw fixture rows reduce to 627 distinct ones,
  so skipping dedup would have reported 2,586 goals instead of 1,868. The
  `wins/draws/losses` tally above still knowingly double-counts because
  `form[]` carries no fixture identity to dedupe on.
  **`refresh()` raises `memory_limit` to its own `REFRESH_MEMORY_LIMIT_BYTES`
  (512M) for the rest of the process/request, one-way, no restore.** The whole
  24h window is fetched up front and held for the entire pipeline (a dozen
  `build*` passes re-read the same decoded arrays), and real payloads decode to
  **~4.8x** their stored JSON (measured: 200 rows, 28.5 MB of JSON -> 138 MB of
  PHP arrays). php-fpm's 128M default was not enough — the admin rebuild button
  died with `Allowed memory size of 134217728 bytes exhausted` inside DBAL's
  `JsonTypeConvert.php:54`, still decoding, before aggregation began. The raise
  lives in the service rather than in `docker/telemetry-generate.sh` so that the
  cron command and `POST /admin/telemetry-config/rebuild` get the same headroom;
  that script's `-d memory_limit=256M` is now only a floor.
  **The window is also PROJECTED, not fetched whole.**
  `LiveTelemetryService::PAYLOAD_KEYS` is the authoritative list of the nine
  top-level `payload` keys any `build*` method reads (`attendance`,
  `excursions`, `fixtures`, `form`, `ledger`, `playerStats`, `promises`,
  `seasonRecord`, `transfers`), and it is passed to
  `SyncRecordRepository::findValidPayloadsSince()`, which narrows each payload to
  those keys **in SQL** (`jsonb_object_agg` over `jsonb_each`) so nothing else
  crosses the wire or gets decoded. `relationships[]` alone was ~60% of all
  payload bytes (up to 162 kB in one row) and is read by nothing here;
  `facilityLevels[]`, `signings[]` and the scalar club aggregates are likewise
  unread (`SyncService` folds those into the `Club` row at sync time). Measured
  end-to-end over 1794 real payloads: **peak 377 MB -> 231 MB**, with 41.7% of
  payload bytes kept. The service owns *what* it reads, the repository owns *how*
  to narrow it — hence the keys being an argument, not a repository constant.
  **A `build*` method that starts reading a new key must add it to
  `PAYLOAD_KEYS`**, or the key arrives absent and that feed category silently
  produces nothing instead of erroring (the Import/Export failure shape CLAUDE.md
  warns about). `LiveTelemetryPayloadProjectionTest` guards both directions by
  scanning the service's own source with comments stripped.
  Any figure derived from `payload.ledger[].amount` (`capitalDeployedPence`,
  `buildLedgerEvents()`'s spend lines) goes through the class's
  `ledgerAmountToPence()` helper to correct for that field's 100x-true-pence
  convention — see `03_data/output/schema.md`'s "`sync_record.payload` field
  conventions" section. `promises[].offer.amountPence` needs no such
  correction.
  Each selected feed item also carries a `clubId` and a per-club `dossier`
  (latest `MatchResult`s + best-rated player from `playerStats[]`), attached by
  `attachClubDossiers()` after selection — the only non-static step in the feed
  pipeline, since it needs MatchResult rows no payload carries. Keyed on clubId,
  never clubName (names are not unique). The dossier feeds the boardroom overlay
  (`_incident_modal.html.twig`). `findBestRatedPlayer()` recovers `position` from
  `fixtures[].{home,away}PlayerRatings[]` (playerStats carries none) and merges the
  club's home kit into the client-reported personal-traits `appearanceConfig`; when
  no appearanceConfig was reported it returns null rather than inventing a likeness.
- **`MarketDataService`** — assembles market listing data (agents/
  investors/scouts/sponsors/staff) into `MarketDataResponse`.
- **`MarketPoolService`** — manages the shared unassigned-entity pool
  (players/staff/scouts/sponsors) and top-up/consume operations.
- **`MatchEngine/AiEngineStub`** — stub match engine standing in for real
  LLM-based match resolution (explicitly out of Phase 1 scope per its own
  code); delegates to `DeterministicEngine` and annotates
  `MatchEngineResult::$computationAudit['engine']` with `requestedEngine`,
  `resolvedBy: 'deterministic'`, `delegatedFrom`, and `fallbackReason`.
- **`MatchEngine/DeterministicEngine`** — full port of `wunderkind-app`'s
  `ResultsEngine.ts`: dominance score (ability/morale/condition, tactics,
  manager, personality, cohesion) drives Poisson-distributed goals,
  then position-weighted goals/assists/cards/ratings across the XI (preserving
  each player's `appearance` object onto `homeLineup`/`awayLineup`). Also
  records a complete step-by-step computation audit in
  `MatchEngineResult::$computationAudit` (`dominance`, `regulation`,
  `extraTime`, `penaltyShootout`, `goalDistribution`, `cardDistribution`,
  `playerRatings`, `outcome`) without altering the RNG sequence. A
  Competition fixture can never end level — a 90' draw triggers a real
  extra-time simulation, and only falls to a coin-flip penalty shootout
  (fabricated but plausible scoreline) if still level after that. Also
  Hands off to `MatchNarrativeGeneratorService` for `narrativePayload`.
  **The engines take no entities.** `resolve()` is
  `(MatchEngineSide, MatchEngineSide, MatchEngineContext)`: the sides carry the
  XI, staff, engine club keys and display name; the context carries the id the
  RNG is seeded from, which engine was requested, and `allowDraws`. `resolveFriendly()`
  is gone — a friendly is simply `allowDraws: true` on the context, so it goes
  through `MatchEngineInterface` and the registry like anything else instead of
  reaching past both. That boundary exists because the real input surface was
  only five entrant getters and one fixture getter, while resolving one friendly
  previously fabricated a `CompetitionTemplate`, `ActiveCompetition`,
  `CompetitionRound`, two `CompetitionEntrant`s and a `CompetitionFixture` and
  then used reflection to overwrite the fixture's private id — all to supply one
  UUID as a seed. Seeds are unchanged by the move, so results are bit-identical.
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
  wentToExtraTime/wentToPenalties/penalty scores, and `computationAudit`),
  persisted by `CompetitionResultsService` into `CompetitionResult` (with
  a guaranteed engine-agnostic `computationAuditJson` envelope even if a
  custom engine omits `computationAudit`).
- **`NameGeneratorService`** — generates names (players/staff/clubs). **214 lines, from
  2,706**: the 2,500-line pool constant moved verbatim to
  `CountryContent\NamePoolRegistry`, and the service now takes a `NamePoolProvider` so a
  country's pool can come from the database. `nationalities()` and `formatFullName()` stay
  **static** (pure enum data and a pure string rule, called without a container);
  `hasNamePool()` became an instance method. The five copies of the English-fallback rule
  collapsed into one `resolveNationality()` — which is how `generateName()` had been left
  **throwing** `Unknown nationality` where the other four fell back silently, turning a full
  `app:pool:warm` red on the 39 poolless countries. It now falls back too, and
  `WarmPoolCommand` warns once per country instead.
- **`NarrativeImportExportService`** — imports/exports narrative content
  (events, playing styles, facility/tactical/archetype templates,
  excursions), plus a `translations` section (non-default-language values
  only, for GameEventTemplate/FacilityTemplate/Excursion/PlayerArchetype
  text fields) — see `NarrativeTranslationService` below and CLAUDE.md's
  "Languages & Translations". `EXPORT_VERSION` bumped to `3` when
  `translations` was added, then `4` when `excursions` (base content, not
  just its translations) joined. `GameEventTemplate`/`PlayerArchetype`
  still export via hand-written field lists (verified complete, but
  guarded against future drift only by `NarrativeFieldCoverageTest`, not
  a `toArray()` method); `Excursion` now exports via `toArray()`, same
  self-verifying convention as `FacilityTemplate`.
- **`NarrativeTranslationService`** — find-or-create for `TranslationKey`s
  linked to one narrative entity field (`ensureKeyFor()`); builds the
  per-(type, language) localization map the `?lang=`-aware endpoints
  (`EventController`, `ExcursionController`, `GameConfigController`,
  `ArchetypeController`) read (`buildLocalizationMap()`); saves the admin
  "Translations" quick-edit screen's submission (`saveTranslations()`).
  The default (EN) value for a narrative field is never stored — always
  read live off the entity via `getFieldValue()`. Deliberately has no
  instance-level cache in `ensureKeyFor()` (see its docblock) —
  `saveTranslations()` dedupes locally instead, since it's the one caller
  that can legitimately ask for the same not-yet-existing key twice (two
  languages, one call). Covers `GameEventTemplate`/`FacilityTemplate`/
  `Excursion`/`PlayerArchetype`.
- **`TranslationCatalogueService`** — builds the generic UI-copy
  `{_meta, entries}` catalogue (`GET /api/translations/{code}` and the
  admin bulk-export), reading `TranslationRepository::
  getGenericCatalogueForLanguage()` in one query rather than per-key.
- **`TranslationCatalogueImportExportService`** — bulk import/export of
  the generic UI-copy catalogue, one language at a time
  (`/admin/translations/content|export|import`). A separate domain from
  `NarrativeImportExportService`'s own `translations` section. Rejects an
  `entries` key that belongs to a narrative-linked `TranslationKey` rather
  than silently overwriting it. `import()`'s optional `$clearFirst` flag
  is scoped to **one language's generic values only** (never a full wipe,
  unlike the narrative import's own "clear existing" checkbox) — a bulk
  import always carries just one language's file, so clearing every
  language would destroy unrelated languages' work as collateral damage.
  `TranslationRepository::deleteGenericForLanguage()` deletes that
  language's rows first; `TranslationKeyRepository::findGenericOrphaned()`
  then prunes any key left with zero translations in *any* language (a
  key still holding a value elsewhere is never touched).
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
  `FacilityTemplateRepository`, `GameConfigRepository`, `LeagueService`;
  each club also gets a `generateIdentity()`-produced home+away kit+badge
  config (`randomKitVariant()` run twice). The home kit's colors become the
  club's `primaryColor`/`secondaryColor` via `NpcClub::setIdentity()`'s
  auto-sync — there's no separate color-pair generator any more (see
  CLAUDE.md's "Kit & Badge Identity"). Its place-name/suffix/stadium-format
  content now comes from `CountryContentRegistry` (keyed by `Country`), not
  its own consts — see that service above and
  `docs/world-generation/adding-a-country.md`.
- **`PeriodResolver`** — resolves stats time-period filters
  (`StatsPeriod`) into query constraints against `SeasonRecord`.
- **`Personality/PersonalityContext`** — builds role-specific
  personality-generation context (independent of skill level).
- **`Personality/PersonalityGeneratorService`** — generates a
  Staff/Scout personality matrix (Gaussian-based, `gaussianInt()`).
- **`PlayerGenerationService`** — generates a `Player` blueprint
  (position, recruitment source, personality matrix feeding into
  attribute derivation). `generate()` takes two optional overrides of
  `PoolConfig`'s defaults, same shape: `$potentialRange` (world-pack tier
  bands) and `$ageRange` (first-team squads, so an NPC side is never built
  out of the pool's youth age band — see `PoolConfig::firstTeamAgeRange()`).
  Both null by default, so existing callers are unaffected; an inverted range
  clamps to its floor rather than reaching `random_int()` as min > max.
- **`PlayerSnapshotSerializer`** — **THE canonical player shape for every
  device-facing response.** See "One player shape" below; start here before
  adding any endpoint that returns a player.
- **`PlayerBrowseSerializer`** — now a thin `withGuardians: true` preset over
  `PlayerSnapshotSerializer`, kept as its own class because "browsing includes
  guardians, world packs deliberately don't" is a real decision and both
  controllers type-hint it. It no longer owns a shape of its own; the two
  formerly-divergent shapes were consolidated.
- **`YouthPoolService`** — the stratified youth draw behind
  `GET /api/players/youth`. **Draws from the existing pool, never generates**,
  so the tier/nationality spreads are properties of what has been seeded, not
  knobs it can turn: it asks for a target per (tier × nationality) cell, backfills
  the shortfall from the rest of the age window, and returns the *realised*
  distribution so an under-seeded pool reads as an empty shelf rather than a bug.
  Bands on `potential`, not `currentAbility` — a 12–14 year old's ability is
  `abilityTarget × potential` with `abilityTarget` only 0.30–0.60 under 21, so
  ability banding collapses a youth pool into one tier (measured: `currentAbility`
  never exceeded 69 across a real 5,412-player pool, so nothing was ever `elite`).
  Age is a `date_of_birth` window (`dobWindow()`), never a second age formula, and
  is served by `idx_player_youth_draw`. Non-consuming by design. The per-tier
  home-nationality mix is read from `PoolConfig` rather than a const, so it is
  admin-tunable; `TIER_WEIGHTS` stays a const deliberately.
- **`SocialPostRenderer`** — renders `SocialPostTemplate` content with
  stat-category/period substitutions.
- **`SocialPostingService`** — publishes to connected social platforms
  via `HttpClientInterface`, raising `SocialPostingException` on failure.
- **`StarterPackService`** — assembles/grants a new club's starter pack
  (players/staff/scouts) via `ClubInitializationService`. **Player draws are
  gated on a `date_of_birth` cutoff** derived from
  `PoolConfig::$playerFirstTeamAgeMin`: the pool deliberately spans youth ages
  to supply `GET /api/players/youth`, so a draw filtered only on ability,
  position and nationality handed new clubs 11-12 year olds. An under-age pool
  now under-fills loudly (`starter_pack.initialize.position_underfilled`) and,
  if nothing at all qualifies, raises `InsufficientStarterPoolException` —
  a visible failure rather than a squad of children. **The assembled pack is
  stored on `Club::$starterPackJson` in the same flush that consumes the pool**
  — those snapshots are the only remaining copy of the squad, since the
  `Player`/`Staff` rows are deleted on consume, so a response lost in transit
  used to leave the club initialized with nothing and a permanent 409 on every
  retry. `POST /api/initialize/starter` now replays that stored pack with
  `replayed: true`.
  `resolveAbilityRange(country, tier)` is public so the diagnostic below reports
  the exact band the real draw will use.
- **`StarterPackDiagnosticService`** — read-only "can a starter pack be built
  for country X right now?" check, behind both `app:starter-pack:check <CODE>`
  and the admin screen `admin_starter_pack_check`. Counts only; creates and
  consumes nothing. It reports the draw as a **funnel** (position → + ability
  band → + age floor → + nationality) because the two filters above can each
  be well-stocked while their *intersection* is empty: a tier band that sits
  inside the youth ability ceiling (`PoolConfig::abilityCeilingFractionForAge()`)
  can only ever be matched by players too young to clear
  `playerFirstTeamAgeMin`, so a 20k-row pool reports zero and the pool totals on
  the admin screens look fine. It borrows `resolveAbilityRange()`,
  `distributeByPosition()` and the age floor from the real draw rather than
  re-deriving them, and suggests a workable band from the abilities
  age-eligible players actually carry. Also returns `bandHoldsOnlyKids`,
  which exists for `StarterPoolAuditService` below — telling a band that is
  merely under-stocked from one whose every member is too young is the
  difference between "queue a top-up" and "an operator has to widen the band".
- **`StarterPoolAuditService`** — the unattended counterpart to an admin
  reading the check screen above and pressing **Warm**. Sweeps every country in
  `StarterConfig::enabledCountries`, runs the same diagnostic, and for any whose
  verdict is **not** `ok` queues bounded `TopUpStarterPoolMessage` chunks onto
  the `pool` Messenger transport (`docker/pool-consume.sh` drains it; the
  handler generates, this service only decides). Behind
  `app:starter-pool:audit`, run hourly by `docker/starter-pool-audit.sh`.
  Three things make it safe to leave running, and all three are the point of
  the class:
    1. **It only acts on a country that cannot currently serve.** The trigger is
       the verdict, not raw pool counts — a healthy country queues nothing
       however far its pool has drifted from the warm floors. It is a
       starter-pack remedy, not a general pool warmer; `app:pool:warm` remains
       that, and is still unscheduled (see `01_overview/output/deployment.md`).
    2. **It never queues work generation cannot finish.** Under the default
       `PoolConfig` (players generated at 12–13, draw floored at 19) no newly
       generated player is ever eligible, so a player shortfall becomes a
       reported *blocker* rather than hundreds of rows an hour that never move
       the verdict. Same for `bandHoldsOnlyKids`. This is the single most
       important behaviour here — the obvious implementation loops forever.
    3. **It will not stack.** `queueIsBusy()` counts undrained `pool` rows in
       `messenger_messages` directly (no transport-agnostic depth API exists,
       and a tracking table of our own would be a second source of truth) and
       skips the sweep while the previous one is still draining.
  The decision itself is `planFor()`, a pure static taking the diagnosis plus
  current native stock — so the precedence that matters (short-but-unfixable
  becomes a blocker, never a chunk) is asserted directly in
  `tests/Service/StarterPoolAuditPlanTest.php` rather than inferred from mocks.
  Targets are per **nationality** (the unit generation actually addresses —
  auditing against the shared pool-wide count would have all enabled countries
  read the same zero and each queue a full deficit for it) and default to the
  same floors `app:pool:warm` uses. It covers all five starter staff roles,
  where `app:pool:warm` only warms `MANAGER`/`COACH`/`CHAIRMAN` — so a country
  short on `DIRECTOR_OF_FOOTBALL`/`FACILITY_MANAGER` is fixed here and never by
  a hand-run warm.
- **`SessionRevocationService`** — signs a `User` out everywhere. Owns **both**
  halves, because either alone leaves them partially logged in: it bumps
  `User::$tokenVersion` (retiring every already-issued stateless access token —
  see `03_data/output/entities.md`) *and* deletes the account's rows from
  `refresh_tokens`, which are real stored credentials (30d TTL,
  `single_use: false`) that would otherwise mint a fresh access token seconds
  later. Rows are matched on `username`, i.e. `getUserIdentifier()`/email, not
  the id. Also exposes `countActiveSessions()` (unexpired refresh tokens only —
  access tokens are stateless and uncountable, so it is a floor, not a total).
- **`SyncService`** — processes client sync payloads
  (`SyncService::process()`); applies an **absolute** `Club::setBalance()`
  call, not an accumulate — a client resync overwrites, doesn't add.
- **`TokenEncryptionService`** — encrypts/decrypts OAuth tokens using
  libsodium secretbox (key generated via
  `sodium_crypto_secretbox_keygen()`).
- **`TransferLeaderboardService`** — computes top-sellers/most-valuable
  transfer leaderboards from `TransferRepository`.
- **`UserLedgerService`** — single writer of `UserLedger` rows (currently just
  dividend draws). `recordDividendDraws()` scans a sync payload's free-form
  `ledger[]` array for `category === 'dividend_draw'` entries and persists one
  `UserLedger` row per match, chaining the user's overall centralized
  balance (`balanceBeforePence`/`balanceAfterPence` on each row) across
  entries. Called from `SyncService::process()` on every live sync, and from
  the one-time `app:backfill-user-ledger` command replaying every historical
  `SyncRecord` — both share this one implementation so the before/after
  chaining logic exists in exactly one place. Idempotent per
  `(sourceSyncRecord, sourceLedgerIndex)` via
  `UserLedgerRepository::existsForSource()`. `dividend_draw`'s `amount` is
  taken as real pence, unlike every other `ledger[].category` (which is
  100x-inflated) — a deliberate choice for this brand-new category, not an
  oversight; see `schema.md`'s `sync_record.payload` field conventions and
  `docs/api/user-ledger.md`. See "Centralized User Ledger" in CLAUDE.md.
- **`WorldInitializationService`** — initializes a country's world data
  (players/staff/scouts pools, starter configs) on first access.
  **`WORLD_PACK_VERSION` is 3 and must be bumped for *any* payload shape change,
  not just the three snapshot builders.** It was missed when the trophy-sprite
  work (`Version20261018202041`, 2026-10-18) replaced the league block's
  `trophyImage`/`trophyColour` with a single `trophy` TrophyConfig, so 84 of 85
  cached packs went on serving the retired asset-slug shape — the cache has no
  TTL and this constant is the only thing that invalidates it. Second time that
  has happened (version 1's note records the all-10s personality matrices).
  Bumping makes every cached row a miss. **`--force` is not the command for
  that** — plain `app:worldpack:warm <CODE>` already rebuilds a stale-versioned
  row, through `forceRebuild`'s atomic path (old entry dropped only once the new
  payload is built), whereas `--force` in all-tiers mode calls
  `deleteByCountry()` up front and leaves the country with no packs at all until
  the run finishes. Warming is a **manual** process here, not part of the deploy;
  the admin dashboard's `stalePacks` figure (`DashboardStatsService`, counting
  `payload_version <> WORLD_PACK_VERSION`) is the signal for what still needs it.
  Nothing breaks if it is never run — a stale pack is a miss, so it self-heals on
  the next request, and that one client pays the rebuild (measured: ~8s for all 8
  tiers of an 8-club country, ~18s for an 18-club one).
  `buildLeaguesPack()`/`buildTierPack()` draw each NPC club's full staff —
  Manager/Coach/Chairman (unfiltered draw) plus Director of Football/Facility
  Manager/Scouts (nationality-filtered with backfill, via `fillStaffRole()`/
  `fillScouts()`) — sized per league tier by
  `StarterConfig::$npcSquadConfig`. Staff (all 5 roles) are pool-deleted on
  consumption like players; Scouts are not (shared pool, like Agent) and are
  surfaced in a new `scouts[]` array on each NPC club snapshot
  (`buildClubSnapshot()`). See `docs/api/npc-club-staff-scouts.md`.
- **`WorldOverviewService`** — builds world overview figures, consumed
  both by `GET /api/world/overview` and directly by `LandingController`
  for server-rendering.
- **`WorldPackCacheService`** — caches per-country/tier "world pack" data
  (`CountryWorldPackCache`). **`getOrBuild()` serializes a cache miss behind a
  Postgres advisory lock on (country, tier) and re-checks under it.** Without
  that, two concurrent `POST /api/initialize/league/{tier}` calls for the same
  tier both missed, both ran the generator — which creates and then DQL-deletes
  a whole tier of `Player`/`Staff` rows — and both inserted, so one died on
  `uq_country_tier` with a 500 after doing all that work. The lock is
  session-level (the generator flushes and clears several times and must not sit
  in one long transaction), released in a `finally`, and degrades to running
  unlocked if it cannot be taken, with the unique index as the backstop.
  `isCached()` exposes whether a call would hit or build, for the per-response
  logging on the tier endpoint.
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
- **`ApiExceptionSubscriber`** (`kernel.exception`, priority **-48**) — turns
  any uncaught exception on an `/api` path into RFC 9457
  `application/problem+json` carrying `error` (a stable machine slug),
  `detail` and `violations[]` (one entry per failed field). Without it a
  `#[MapRequestPayload]` validation failure rendered Symfony's **HTML** error
  page whenever the request had no `Accept: application/json` — which is what
  the app sends — so the client got a 422 with nothing machine-readable in it
  (Sentry `155644075`, `POST /api/owner-avatar` mid-onboarding). The priority
  is load-bearing: it sits between the security firewall's own exception
  listener (1), so lexik's JSON 401 is left alone, and
  `ErrorListener::onKernelException` (-128), so the HTML renderer never sees
  an `/api` path. `/admin` is untouched — it is a browser UI. A 5xx `detail`
  is always a fixed string; the real message is logged, not returned.
  Contract: **Error responses** in `docs/frontend-integration.md`.
- **`IdempotencySubscriber`** (`kernel.request`/`kernel.response`) — replays a
  cached response for a repeated `Idempotency-Key` on POSTs under
  `/api/competitions/`, `/api/friendlies/`, `/api/inbox/` and the exact path
  `/api/club/initialize`. It only replays a request the server actually
  *finished*. **`/api/league/conclude-season` is deliberately excluded** despite
  being what the client's retry queue was built for: it has a domain-level
  guarantee (club+season, which also survives a request killed mid-flight), so
  the body-hash check could only ever fire as a false positive — and the client
  drops a 4xx, which would turn that into permanent loss of a season. The
  principle: the header covers paths with no domain-level guarantee, and a path
  that has one should not take on the header's failure mode. A key sent to a
  non-allowlisted path is ignored, not rejected.

## One player shape

**The world-pack player snapshot is the universally expected structure whenever
the API returns a player object to the frontend device.** Any new endpoint that
returns a player emits this shape — do not invent a per-endpoint variant, and do
not reach for a different serializer.

It is produced by `PlayerSnapshotSerializer`. `WorldPackSnapshotBuilder::
buildPlayerSnapshot()` and `PlayerBrowseSerializer` both delegate to it, so the
world pack, starter pack, `/api/scout/search`, `/api/players/foreign` and
`/api/players/youth` all serve byte-identical keys and the client needs exactly
one upsert path.

There used to be two shapes that overlapped on ~20 keys and disagreed on five.
Consolidation was a strict **superset** — no key either shape emitted was removed
or redefined:

- browse endpoints **gained** `morale`, `isActive`, `potentialTier`
- the world-pack snapshot **gained** `tier`, `overall`, `potentialTier`

Two deliberate exceptions to "one shape", both opt-in flags on the serializer:

- **`guardians`** — on for browse and youth, **off for world packs**.
  `Player::$guardians` is a lazy collection, so including it in a country-sized
  pack would fire a query per player across thousands of players.
- **`isYouth`** — set only by `/api/players/youth`, and *omitted* rather than
  emitted as `false` elsewhere, so no pre-existing response gained a key.

`tier` vs `potentialTier`: `tier` keeps its original `currentAbility` meaning so
already-shipped clients are unaffected; `potentialTier` bands `potential` and is
the one to classify youth on. `PlayerSnapshotShapeConsolidationTest` pins both
legacy key sets literally — those arrays are a historical record of what shipped,
not a description of current output, and must never be edited to match new output.

No `src/EventListener/` directory exists.

## Economy scaling (`App\Service\Economy`, added 2026-11-12)

**`EconomyScaleResolver` — THE only place country and tier compose into a money
figure.** Read it before adding any per-country or per-tier money calculation;
the point of the class is that there is nowhere else to put one.

Why one class rather than a helper per caller: the chain is short, but its
inputs live in three tables (`country_profile`, `game_config`,
`starter_config`) and its output gets *baked into generated rows that outlive
the request*. Two call sites composing the same factors in a different order,
or rounding at a different step, produce clubs in the same division that
disagree about what a player costs — and nothing surfaces that except a player
noticing. This codebase has already made that mistake once, with the three
copy-pasted wage-multiplier tier-walks in `WorldPackClubGenerationService`,
`MarketPoolService` and `StaffGenerationService`.

**Integer basis points, one division at the end.** Every factor is an integer
in basis points and the chain divides exactly once, with `intdiv`, where it
becomes pence. No float enters it. The single exception is the ability curve,
which needs `pow()` with a fractional exponent: it is computed in ONE method
and rounded ONCE, and the result is carried as **pence × 10⁶**
(`WAGE_SCALE_UNIT`) so the per-player multiply downstream stays integer too.
That unit is not fussiness — `S_tier` for a bottom division is a fraction of a
penny per unit of ability^k, so rounding it to pence would quantise every wage
in the division to a handful of values, and at a small country factor would
round it to zero and make every player free.

**The reference squad is read from the same config the world generator uses** —
`starterConfig.npcSquadConfig` for size, `starterConfig.leagueAbilityRanges`
for the ability band — so the figure the wage scale is calibrated against is
the figure that actually gets paid. There used to be a third ability table —
`gameConfig.leaguePlayerAbilityRanges`, a stored column published to the app
that no server code read and that disagreed at every England tier. It was
**dropped** on 2026-11-13 (`Version20261113160000`); the published key of that
name is now built from `starterConfig.leagueAbilityRanges` by
`EconomyConfigPublisher`, so one table feeds both pricing and publishing.

**`tierCountFor()` moved here from `NpcClubGenerationService`**, which was the
only place in the codebase that read `leagueTierCount`. It belongs with the
other per-country economy inputs, and the resolver needs it anyway to clamp a
requested tier to a pyramid that does not have it. `NpcClubGenerationService`
delegates; `reputationForTier()` still reads it on a hot loop, which is why the
resolver memoises per request (a world-pack build resolves the same country and
tier once per club, and without the cache the baseline lookup is one query per
club).

**Raw row indexing, reversing an earlier convention.** Tier t reads row t; a
4-tier country uses rows 1-4 and is not stretched across the eight-rung tables.
`GameConfig::scaleTierToEight()` is gone and
`getNpcClubBalanceRangeForTier()`'s `$tierCount` parameter with it. The
reasoning is in that method's docblock: one link of a money chain stretching
while the others do not is worse than a bottom division that is merely
generous. **Still asymmetric with two interpolations, deliberately** —
`NpcClubGenerationService::reputationForTier()` and
`GameConfig::getNpcClubSizeWeightsForTier()` span the country's own depth,
because they have no rows to index and a fixed divisor of 7 would strand a
3-tier country 28% along its own scale. `GameConfigTierScalingTest` pins both
halves of that line.

`inflationIndexBp()` is computed here and applied **almost nowhere** — never to
a generated row, a published value, or a world pack. Compounded in integers one
season at a time rather than with `pow()`, because an operator may have to
reproduce a ceiling by hand from the audit record and `(1+r)^n` in floating
point does not reproduce by hand. The season arrives from a device-mirrored
column so it is bounded (200) rather than trusted.

`EconomyScale` is the returned value object, and **the breakdown is the
product, not a debugging extra**: the admin preview screen renders exactly its
fields, and a resolver returning a single integer would be impossible to
sanity-check before turning scaling on for a country. `isResolvable` is false
when the turnover row is unauthored — the shipped state.

**`SponsorBandResolver` — what a league sponsor pays, scaled by country.** Replaced
**byte-identical** copies of the roll loop in `LeagueService::rollLeagueSponsors()` and
`WorldInitializationService::rollLeagueSponsors()`; both are still public (one is called by
`WorldPackTierAssemblyService`) and both now delegate.

That is the **fifth** duplication this overhaul collapsed, after three copies of the wage
tier-walk and three of the ability-band lookup. The pattern is consistent enough to name: money
arithmetic here gets copied to the second caller rather than extracted, and every copy is a
place the two can disagree about a figure that then gets stored in a cached pack.

**COUNTRY FACTOR ONLY, NOT COUNTRY x TIER**, and that is the decision most likely to be
"corrected" later. A league's tier variation is already expressed by `League::$sponsorCount`
(authored per tier) and the size mix its sponsors draw from, so applying the tier factor too
would double-count it and quietly make a tier-1 pot enormous. `SponsorBandResolverTest` asserts
two tiers of one country agree even with a 5x tier-1 factor set.

**Not gated on `economyWageModel`** — a sponsor band has nothing to do with how a player is
priced — so it applies the moment a country factor is set. That is why the runbook requires a
`WORLD_PACK_VERSION` bump on a factor change and not only on a model flip: `sponsorPot` is part
of the cached league block.

**`NpcClubGenerationService::balanceForTier()` is country-scaled since 2026-11-12**, and that
was a GAP rather than a choice: decision 4 of the economy brief lists NPC balances among the
things the backend bakes the country factor into, and the step-5 wage unification threaded
country and tier through players and staff while leaving the balance table on raw figures. A
club in a country scaled to 20% was paying **20% wages out of 100% money** — not a cheaper
country, a different game. The band is scaled BEFORE the city-size skew, so a big-city club
stays the same multiple of its division's average whatever the country is worth. Found during
the step-8 threshold survey; see `docs/economy-threshold-review.md`.

**`SeasonInvestmentService` — the owner gifting their own money into a club, once a season.**
Modelled on `ClubFundingService`: same natural-key idempotency, same "the server debits the
account and the DEVICE credits the club" split, same exception type.

**THE SEASON COMES FROM `SeasonRecord` ROWS, NOT `club.currentSeason`**, and that is the decision
worth knowing. That column is device-mirrored — `SyncService::process()` overwrites it from the
payload — so a client could report season 50 and both raise its own ceiling (the ceiling grows
with the inflation index) and claim for seasons it never played. `SeasonRecordRepository::countByClub()
+ 1` is backend-owned: those rows are written only by `concludeSeason()`, one per `(club, season)`
behind `uq_season_record_club_season`, so a device claiming season 50 writes ONE record and moves
the count from n to n+1. Chosen over a new counter column because it needs no migration, no
second write path, and reuses a constraint that is already load-bearing.

Residual exposure, stated rather than hidden: a late retro-conclude for a genuinely missed season
also adds a row, so it opens one extra window — bounded at one per conclude that actually
archives a season.

**The replay check runs BEFORE the amount and ceiling checks**, deliberately: a retry after a
dropped response must succeed even if an operator has since lowered the ceiling, or re-tuning the
economy would turn a harmless retry into a permanent failure for a debit that already happened.

`creditClubPence` is an INSTRUCTION, not a report — the service never writes `club.balance`,
because the device overwrites it absolutely on every sync.

**`WageModelResolver` — what a generated player or staff member costs, and the only place that
answers it.** Replaced FOUR copies of the same arithmetic:
`WorldPackClubGenerationService::playerWageMultiplier()`,
`MarketPoolService::getWageMultiplier()`, `StaffGenerationService::getWageMultiplier()` and —
worst — `GenerateMarketDataCommand`, which had neither and used its own `random_int(10, 40)`,
ignoring both `contractValueRandMin/Max` (live: 2000/2800) and the tier multipliers entirely. A
player it created was priced roughly two orders of magnitude below an identical pool player. It
also left every scout it made on wage 0, which `BackfillScoutWagesCommand`'s zero-sentinel reads
as "never priced".

**`legacy` is a supported mode, not dead code**, and it must stay byte-identical. The old sites
did `(int) ($ability * $roll * $multiplier)` — a TRUNCATING cast on a float product — and that
is reproduced verbatim. The ROLL IS A PARAMETER because `random_int()` is a CSPRNG and cannot be
seeded: byte-identity can only mean "the same formula on the same draw", which is exactly what
`WageModelLegacyParityTest` asserts across every ability 0-100 against the pre-unification
expressions held independently in the test. **Those copies must never be refactored to call the
resolver** — the whole point is that they hold the old behaviour from outside.

Two oddities survived on purpose: the tier-walk's `<` is STRICT, so ability exactly 15 takes the
x6 band not x4; and an ability at or above the last `maxAbility` falls out of the loop onto the
last row via `end()`, because the live table has no null catch-all. Both priced every row in the
world.

**TWO POPULATIONS, TWO TREATMENTS** — the distinction that governs where the model can apply at
all. Per-country rows (world-pack clubs) are generated for a known country and tier, so they are
priced at generation and stored, which is why flipping the model needs a `WORLD_PACK_VERSION`
bump. Shared-pool rows are ONE row served to every country at every tier, so they are stored
legacy and priced at serve time — in `PlayerBrowseSerializer` (re-priced from the ability curve)
and `MarketDataService` (country x tier factor only, since a scout's pay comes from bands the
model has no opinion about). **The pricing is deliberately NOT in `PlayerSnapshotSerializer`**,
which `WorldPackSnapshotBuilder` also delegates to: re-pricing there would change cached pack
content on every read with no version bump.

Staff pay under `turnover` reuses `GameConfig::$economyStaffRoleBandsPence` for BOTH jobs — the
midpoint is the role's weight, the spread is how ability moves pay within the role — scaled so
the tier's reference complement (the counts in `npcSquadConfig`) costs the configured staff
share. Reusing the band is what avoids asking an operator to re-tune five hand-tuned roles
against a knob that did not exist.

**`averageFirstTeamWagePence()` is the single definition three things must agree on**: the youth
scholarship ladder, the scholarship cap, and the gate command's inversion check. They disagreed
once — the gate priced its table through `turnover` but asked for the scholarship under the LIVE
model, so with the model on `legacy` it compared a flat £250 against turnover wages and raised
two problems **no authoring action could clear** (16 under `legacy`, 14 under `turnover`, the diff
being exactly those two lines). A gate that cannot reach zero blocks the flip forever.
`youthScholarshipPenceUnder()` takes the model as an argument for that reason, with
`youthScholarshipPence()` delegating so the cap keeps one implementation. Found by RUNNING the
gate against real data, not by reading it.

**The scholarship itself is an anchored ladder, not a flat figure.** `clamp(anchor x
averageWage(tier) / averageWage(1), floor, capBp x averageWage(tier))`, where only tier 1 is
authored and both new columns default to 0 (= off, the old flat-plus-cap behaviour). Scaling by
`S_tier` instead was modelled and rejected: it carries 1.72x of spread against the wage ladder's
1,047x, so a £1,500 anchor would put a tier-8 scholar on £873 against an average professional's
£127. Floor before cap, because the floor is a preference and the cap is the invariant.

**`app:economy:wage-table` is the gate, not a debugging aid.** It reads the live config rows and
exits non-zero on SIX checks. Check 6 is the newest and the only guard on **`league.tv_deal`,
the one money figure the country factor never reaches** — it is a stored absolute an operator
types in, so a factored country would pay a fifth of the wages and bank full baseline TV. Check 3
cannot see it (TV at 5x its intended share still passes a 100%-of-turnover bound), so check 6
compares against the BASELINE country's own figure times the factor and reports what to author.
Needed `EconomyScaleResolver::baselineCountryCode()`, since comparing against the base year needs
the code rather than just the factor. The other five are (S_tier monotonic; no cross-tier ability inversion; TV a sane
fraction of turnover; the squad bill matching its own definition). Check 4 earned its place
immediately by catching the reference squad being summed over continuous abilities when
`Player::$currentAbility` is an int — 2.5% out on England's narrow tier-8 band, invisible on a
wide one.

**`LeagueSizeResolver` — the TV pool's divisor, and the one place a league's size is
answered.** Read its docblock before writing anything that asks "how many clubs are in this
league", because the obvious query is wrong: **a `League` row is a template shared by every
save** in its country and tier, and England tier 8 had 147 real player clubs pointing at one row
on 2026-11-12. The table a player sees is assembled on their device.

`league.slots` is therefore authored with **0 meaning "derive"**, and the derivation (NPC rows
plus one for the country's bottom division) is the fallback. The reason the size is stored is not
the arithmetic — it is that a derived divisor would move whenever an operator clicked Generate,
silently re-pricing a tier's TV money for every shipped build.

`derivedTvDealPence()` returns **null** for a league with no pool (every league, until somebody
authors one) and also for a pooled league with no resolvable size — rather than dividing by a
clamped 1 and publishing a whole pool as one club's share. `syncTvDeal()` is the only write path
onto `tvDeal` when a pool exists, so the two columns cannot disagree about arithmetic. Memoised
per country per request, with `forget()` for the bootstrap case that creates leagues, fills them,
and only then has a real size.

**`EconomyConfigPublisher` — the economy blocks the client reads, built once for three
surfaces.** The economy reaches the app through the sync `gameConfig` block, `GET
/api/game-config` and (for the country-agnostic half) the public `GET /api/starter-config`. The
sync block is a **ternary with two hand-written branches**, and the hard rule is that both carry
an identical key set.

Three hand-maintained copies of a six-key block is a drift machine, and the drift is silent in
the worst direction: a key on one branch and not the other only shows up in the no-config case,
which is unreachable in practice (`getConfig()` creates the row) and therefore never exercised.
So the block is built here and spread into all three with `...`.

**The fallback is `new GameConfig()`, not restated literals.** `clientBlock()` takes a nullable
config and falls back to a fresh entity, so the defaults ARE the column defaults by
construction. That is the `youthScholarshipWagePence` lesson commented in `SyncService`: the
client guards with `?? fallback` rather than falsiness, so a restated `0` sails past it and
silently becomes the live value.

**What the app gets and what it deliberately does not.** `economyTierBaseline` carries resolved
absolutes for one country — turnover and wage scale per tier — and **no country factor**.
Publishing one would invite the client to apply it a second time to an already-scaled figure.
`resolvable: false` distinguishes "the operator has not authored this tier" from "this tier is
worth nothing", which a bare `0` cannot express, and the first is the shipped state.

`?country=` on `/api/game-config` is **optional and absent means parity** — that endpoint is
`PUBLIC_ACCESS`, so unlike sync there is no club to resolve a country from. An empty code
resolves at parity without a second code path, because `findByCode('')` finds nothing.

Guarded by `tests/Service/SyncServiceGameConfigParityTest.php`, which parses `SyncService`'s own
source to compare the two branches (asserting **order** as well as membership, since same-members
different-order is the signature of somebody patching one branch to match the other) and proves
neither restates a key the builder spreads in. Verified to fail on injected drift.
See `docs/api/economy-config.md`.

## Location & land economy (`App\Service\Location`, added 2026-11-03)

- **`Haversine`** — great-circle distance in PHP *and* as a SQL fragment, in one file so
  the two cannot drift. There is **no PostGIS** (`postgres:16-alpine` in both compose
  files), so rival ranking is a bounding-box prefilter against `idx_location_lat_lng`
  plus this expression in `ORDER BY`. The `acos()` argument is clamped in both forms:
  floating point can push the dot product outside [-1, 1] for identical points, which is
  a domain error in Postgres and `NAN` in PHP.
- **`LocationCatalogueService`** — the read side of the `location` table for world
  generation, returning **exactly** the array shape `NpcClubGenerationService` always
  consumed. Process-scoped memo (`private array $byCountry`), explicit column projection
  via `getArrayResult()`. **Deliberately not a cache pool**: the data is admin-mutable
  and a stale entry would generate clubs in retired towns. Holds the single
  `PLACEHOLDER_PLACES` const that used to be inlined twice in the generator.
- **`LocationClassificationService`** — the BIG/MEDIUM/SMALL banding (top 20% / bottom
  50%, capital forced BIG), moved verbatim out of
  `NpcClubGenerationService::classifyPlaces()`, which now delegates to it so its existing
  tests prove the move changed nothing. `reclassifyCountry()` persists the result.
  **Returns the distribution, not the number of rows changed** — those differ, and the
  difference is misleading because `city_size` defaults to SMALL on a new entity.
- **`CountryReadinessService`** — seven checks gating a country going Live: eight league
  tiers, `StarterConfig::$leagueAbilityRanges`, club-naming content, ≥1 location, ≥1
  plot, nothing outstanding in enrichment, and every active location actually priceable.
  Reuses the existing league/config machinery rather than inventing a parallel one, and
  keeps `isGenerationCapable()` / `enabledCountries` distinct as
  `docs/world-generation/adding-a-country.md` insists.
- **`LocationRivalService`** — directional nearest-four within 25 miles. **Re-runnable by
  design**: coordinates arrive one location at a time, and a newly placed town changes
  its NEIGHBOURS' nearest four too, so enrichment nulls `rivals_computed_at` across a
  radius and `--stale-only` picks those up. Until a country is fully enriched its rival
  sets are provisional, which is invisible because `CountryProfile.status` gates the
  country out of the API.
- **`StadiumEstimateService`** — build price from population → plausible capacity → cost,
  plus the site's land value. **Real-world construction rates (~£1,500/seat), so London
  is ~£135M**, and there is deliberately **no affordability ceiling**: the gap between
  price and balance is the progression, since funding a flagship city is meant to take
  ten to fifteen clubs. Deterministic, because a hold freezes a quote. Estimates are
  superseded, never deleted.
- **`PlotHoldService`** — acquire/release. Does **no check-then-insert**: both racing
  callers attempt the insert and the partial unique lets one through, then
  `explainConflict()` works out *why* the loser lost, because "someone else is holding
  this" and "you already hold a plot" mean different things to a player.
- **`PlotClaimService`** — the claim transaction, in this order: `SELECT ... FOR UPDATE`
  on the hold (the serialisation point for a double-tapped CLAIM), validate, check
  credit, insert the claim `ON CONFLICT DO NOTHING`, consume the hold, then charge.
  **Charged last, so losing the race costs the player nothing.** Pays from
  `UserLedgerService::append()`, never `club.balance`.
- **`Enrichment\*`** — `LocationEnricherInterface` (provider-agnostic; the choice is
  deliberately open), `UnconfiguredLocationEnricher` (the wired default, which **refuses
  loudly** — the missing-`FIREBASE_SERVICE_ACCOUNT_JSON` lesson),
  `EnrichmentValidator` (per-FIELD accept/reject against the country bbox and value
  bands; a partial answer keeps its good half and lands NEEDS_REVIEW),
  `LocationEnrichmentService` (the lease, the dispatch, the write-back),
  `LocationEnrichmentOrchestrator` (the three admin triggers, with a stacked-sweep guard
  copying `StarterPoolAuditService`'s undrained-queue count).
- **`CountryEnrichment\*`** — the same seven-file shape one level up, for the COUNTRY row:
  `CountryProfileEnricherInterface`, `ClaudeCountryProfileEnricher`, `Unconfigured…`, a
  factory on the same `LOCATION_ENRICHER_*` trio, `CountryProfileValidator`,
  `CountryProfileEnrichmentService`. **Synchronous, and that is the whole design decision** —
  one call for a dozen short scalars, so a queue would cost a transport, a handler, a consumer
  and a poll to make a two-second call feel slower.
  **The check worth knowing about is in the validator**: a proposed bounding box that excludes
  towns already placed in that country is refused outright and the rejection names the extent
  those towns span, because those coordinates have already been through `EnrichmentValidator`
  and a review pass. It gets stronger the more of a country is enriched.
  `EnrichmentValidator::isUsableBbox()` was made public static so both validators share one
  definition of a usable box.
- **`CountryContent\CountryNamingContentProvider`** — an operator's naming content if there is
  one, `CountryContentRegistry`'s const otherwise. **The registry is not replaced and nothing
  is removed from it**: its statics stay static (`worldRegion()` and `capitalAliases()` are
  called from enum methods with no container) and `CountryContentRegistry::missingCategories()`
  keeps its exact behaviour, `places` included — which is what leaves `CountryContentRegistryTest`
  and `PolandContentTest` untouched. The provider's own `missingCategories()` never reports
  `places` and is what `CountryReadinessService` now asks. No memoisation: `findByCode()` is a
  PK `find()`, so the identity map already serves it, and a second cache could only go stale
  against the admin's own save.
- **`CountryContent\NamingContentValidator`** / **`NamingContentService`** /
  **`CountryContent\Suggestion\*`** — the write half and the one-call-per-country AI
  suggester. The strictness is all in one place and for one reason: a stadium format is handed
  to `sprintf()`, which THROWS in PHP 8, so an operator-typed percent sign would otherwise be a
  fatal inside club generation a long way from the form. Four rules guard it, the last of which
  simply runs the template. `NpcClubGenerationService::generateStadiumName()` wraps its
  `sprintf` as a second line of defence.
- **`CountryContent\NamePoolRegistry`** / **`NamePoolProvider`** / **`NamePoolValidator`** /
  **`NamePoolService`** / **`CountryContent\Suggestion\*NamePool*`** — the same
  override-else-registry shape for the two **person**-name lists, with three differences that
  matter. **(1) It memoises, and the sibling provider's reasoning for not doing so is false
  here**: `MarketPoolService` calls `$em->clear()` every fifty players (ORM 3's `clear()` is
  `clear(): void`, so the `Player::class` argument is discarded — a full identity-map clear),
  so Doctrine serves nothing, and a 71-country warm reads these ~116,000 times. The memo is a
  **process-scoped instance field** (not `static`, which would leak between tests; not PSR-6,
  which buys nothing once 116,000 reads collapse to ≤71 and turns a one-second staleness
  window into an indefinite one). `forget()` exists for one caller: the admin handler that
  saves and re-renders in the same request. **(2) The validator is separate from
  `NamingContentValidator` and must stay so** — target 150, floor 10, ceiling 400, and the
  dedupe is `mb_strtolower(trim())` and **never** `ClubNameNormalizer::normalize()`, which
  strips diacritics and would collide `Güneş`/`Gunes` and `Łukasz`/`Lukasz`. Accent-insensitive
  collision is right for club names and silently destructive for people's. **(3) The AI asks
  one list per call and only for the shortfall**, names already held sent as exclusions, driven
  by `BMC.runJobs` rather than Messenger — a list of bare strings is an order of magnitude
  smaller than the town pull's 300 objects, which is the only reason that one needed a queue.
  Revisit if the target passes ~300 per list. CLI twin: `app:names:top-up`.
- **`StarterAbilityRangeService`** — `app:country:bootstrap` step 3, lifted out so the admin
  button and the command cannot drift. It also forced a fix on the Starter config screen: that
  form's grid is built from countries that already have `League` rows, and its save wrote the
  whole `league_ability_ranges` column, so **every unrendered country's band was being deleted
  on any save** — including the one this button exists to create. The save now merges.

**Sixth Messenger transport, `enrichment`.** Its own queue for the reason every transport
below `async` has its own: an "enrich all remaining" sweep queues one message per town —
865 on a full pass — each making an external API call of unpredictable latency, and it
must not get in front of `competition` (latency-sensitive against wall-clock time) or
push notifications. It is also the only queue whose work costs money per message.
Drained by `docker/enrichment-consume.sh`; `in-memory://` in tests.

**`UserLedgerService::append()`** is new and is the single writer for every
backend-originated ledger entry. It takes a **per-user advisory lock**
(`pg_advisory_xact_lock`), because `getCurrentBalance()` is an unlocked read and two
concurrent writers would fork the before/after chain — survivable while sync was the only
writer (the device serialises per club), not survivable once a player can tap "buy"
twice. Idempotent on `(sourceKind, sourceRef, type)`, and it refuses a positive debit.

## Club buyouts (`App\Service\Club`, added 2026-11-14)

An admin picks a user's club, is shown what it is worth, adjusts the figure, and authorises a
buyout. The owner is credited and the club is then **destroyed**. Built queue-first so the
go-live batch is the same code path, not a second implementation.

- **`ClubSaleValuationService`** (`clubsale-v1`) — what we OFFER for a club.
  `base = balance + facilities + land + stadium`, then
  `estimate = round(base x (1 + repFactor) x GENEROSITY_MULTIPLIER)`.

  **It is deliberately NOT `ClubValuationService` (`club-v1`), and must not be merged with
  it.** That one answers "what is this club worth" for a plot release and answers it
  conservatively; this answers "what do we pay to thank someone for the club they built" and
  the honest answer is an over-estimate. A sell-back paying this figure would make churning
  plots profitable. It reuses `club-v1`'s `breakdown()` for the balance and facility terms
  rather than re-deriving them, and nests that output whole under `club` so a reader can tell
  which model produced which term.

  **Reputation is an UPLIFT, not a price per point.** Nothing in this codebase prices a
  reputation point in money — `club-v1` excludes it explicitly — so a flat pence-per-point
  would be an invented constant applied to an unbounded `bigint`. As a share of what the club
  otherwise holds it scales with the club, and `REPUTATION_UPLIFT_CAP` (0.50) stops a
  device-reported outlier becoming a payout.

  **Land has four bases, in descending confidence**, recorded by name in the working:
  `active_claim` (the plot's own price) → `home_location` → `name_match` → `unmatched`.
  `Club::$homeLocation` is NULLABLE and expected to stay so, so "no link" is the COMMON case
  here, not an edge one: the clubs this feature rewards are mostly the ones founded before
  the land economy. `name_match` and `unmatched` are flagged in the UI, because the land term
  can be the largest one. Land is taken at **100%**, and stadium at 100% of what was paid —
  not `PlotSellBackService`'s 0.9/0.5, which exist to make churning cost something.

- **`TownNameMatcher`** — extracted from `NpcClubLocationLinker`'s private `matchTown()` for
  the basis above. The linker's own docblock refuses a SQL reimplementation of these rules; a
  second PHP copy would be the same mistake with a shorter fuse. A player club is a weaker
  case than an NPC one (a player typed the name), so a hit is evidence, not proof.
  `NpcClubLocationLinkerTest` is the parity guard and passes unchanged.

- **`ClubSaleService`** — `execute(ClubSale)`: credit, scrub, mark completed.
  **Credit first, scrub second, and NOT in one transaction together** — the same reasoning
  `PlotSellBackService::execute()` documents for the same pair. A crash after the credit
  leaves a paid player who still has their club: visible, and the retry pays nothing further
  because the credit is idempotent on `('club_sale', saleId, CLUB_SALE)`. A scrub that ran
  first and then crashed leaves nothing to recompute a payout from. So the recoverable failure
  is the one we choose to have. The credit is attributed to `club: null`, like the sell-back
  credit, so it outlives the club.

  Zero is allowed and writes no ledger row (an operator clearing a worthless club should not
  put a £0 entry in somebody's visible history). A negative figure is refused — `CLUB_SALE` is
  absent from `isDebit()`, so `append()` would otherwise happily write a debit dressed as a
  reward. **A buyout is not reversible**: correct a mistake with a `REVERSAL` entry, never by
  re-running the row.

- **`ClubScrubService`** — deletes one club and everything pointing at it, in one transaction,
  while the owner survives. **That survival is the whole difference from
  `AccountDeletionService`**, which can let `user_ledger.user_id`'s CASCADE take the money
  history with it.

  **Step 1 is `PlotClaimRepository::releaseActiveForClubs()` and is not optional.**
  `plot_claim.club_id` is SET NULL, so a club delete does not block on a claim — it leaves one
  active with a NULL club, and because `uq_plot_claim_active_plot` keys on `plot_id` alone that
  plot is then **permanently unbuyable**. That repository method existed with no production
  caller until this service; it is also why `DeleteAdminController`'s club delete could strand
  a plot, which is fixed by pointing that route at this service.

  It shares `AccountDeletionService::BLOCKING_CLUB_DEPENDENTS` (now public) rather than
  restating the table list — one record of which FKs block a club delete, for two paths.

  **`user_ledger` rows are DETACHED, never deleted** (the FK does it; no statement needed).
  Deleting a club's entries rolls the owner's balance backwards, because the balance is a
  chain of before/after snapshots — a defect this codebase has shipped once already.

  **Two things are deleted here that the account path retains, by explicit instruction**, and
  both have an accepted cost: FINISHED `competition_entrant` rows (their frozen
  `clubDisplayJson` is what renders a settled bracket, so a completed cup shows a blank
  opponent — the shape of the bug fixed 2026-11-02, reintroduced deliberately because a
  bought-out club should leave no trace), and `leaderboard_period_archive_entry` rows
  (settled boards get ranks that skip a number).

  `Player`, `Staff`, `Agent` and `Scout` carry **no `club_id`** — they are pool entities and
  nothing here touches them. `DeleteAdminController`'s old comment claiming otherwise was
  wrong.

- **`App\Service\Admin\MoneyInput::toPence()`** — extracted from
  `LeagueAdminController::parseMoneyToPence()`, which now delegates. Holds two shipped money
  defects' fixes: thousands separators survive (a bare `(int)` cast stopped at the comma and
  stored £10 for "1,000") and decimal pounds survive (`((int) $digits) * 100` truncated
  "1000.50" to £1,000). **Garbage returns null, not 0** — callers must say which they mean;
  the buyout refuses the submission, because a payout of nothing arrived at by mistyping is
  not one anyone authorised.

**Tenth Messenger transport, `clubsale`.** Separated from every other queue on **blast
radius**, not throughput — it is the only one whose messages credit real money and then
irreversibly delete player data. Two operational properties follow: a stuck buyout appears
alone in `messenger:failed:show`, and commenting out its crontab line pauses exactly the
destructive work and nothing else, which is the control an operator wants mid-batch at
go-live. `--limit` is low to bound damage, so a defect found on sale five is a five-club
problem. Drained by `docker/clubsale-consume.sh`; `in-memory://` in tests (NOT `sync://` —
running the real handler in-process would delete a club as a side effect of asserting that a
button posted). `SellClubMessage` carries only the `club_sale` id.

**Batch path:** `app:clubs:sell-batch` (`SellClubsBatchCommand`) — `--club`, `--user`,
`--all`, `--limit`, `--require-plot`, `--dry-run`. It QUEUES, like
`CompetitionResolveRoundsCommand`. It pays the estimate **unreviewed**, which is the real
difference from the overlay, so `--require-plot` exists for an operator who would rather
handle inferred-town clubs by hand. Spoof clubs are refused even when named explicitly.
