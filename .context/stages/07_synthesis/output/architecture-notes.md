# Cross-Stage Architectural Notes

Connections backed by content actually present in the stages they link.

- **API Platform is installed but unused.** `composer.json` requires
  `api-platform/core`, and `config/packages/api_platform.yaml` mounts it
  at `/api` — but no entity carries `#[ApiResource]`
  (`.context/stages/04_interfaces/output/routes.md`), so it generates no
  routes. Every real `/api/*` route is a hand-written controller sharing
  that prefix. Anyone tempted to add a quick CRUD endpoint via
  `#[ApiResource]` should know it would be the *first* one in the repo —
  worth a deliberate decision, not a reflexive shortcut.
- **The Competition feature was built in one atomic push, and it shows
  in both the schema and the git history.** Migration
  `Version20260916112510`
  (`.context/stages/03_data/output/migrations.md`) created the entire
  Competition table set in a single migration, and
  `.context/stages/02_architecture/output/git-activity.md` shows commits
  consistently touching `Entity/Competition/`, `Repository/Competition/`,
  `Service/Competition/`, and their tests together — this is a
  cleanly-bounded module, not one that grew organically across many
  small migrations like most of the rest of the schema.
- **A recurring "new background job → Dockerfile change" pairing.**
  `git-activity.md` notes that adding a new `app:*` console command is
  consistently paired with a Dockerfile cron-table change and a new
  `docker/*.sh` wrapper script in the same commit (e.g. commit `53c6fff`
  adding `CompetitionProcessRoundsCommand`). If you add a new scheduled
  command, follow that same three-part pattern rather than relying on
  Symfony's own scheduler.
- **Admin panel customization goes deeper than most Symfony/EasyAdmin
  apps.** `.context/stages/05_ui/output/design-system.md`'s themed
  palette/typography and the custom compound-field widgets
  (`appearance_theme.html.twig` + `appearance-widget.js` +
  `avatar-compositor.js`; and the sibling `kit_identity_theme.html.twig` +
  `kit-identity-widget.js` + `kit-compositor.js` for NpcClub kit/badge) are
  wired through `Controller/Admin/DashboardController.php` and
  `PlayerCrudController`/`ScoutCrudController`/`StaffCrudController`/
  `AgentCrudController`/`NpcClubCrudController` — exactly the controllers
  `.context/stages/02_architecture/output/git-activity.md` flags as one of
  the two active hotspot clusters. Admin-panel changes in this repo are as
  much UI work as backend work.
- **One remaining cross-repo "must stay bit-identical" invariant: `ClubNameNormalizer`**
  (must match the client's `clubName.ts` — not enforced by a shared package
  or test, changing it without checking the frontend repo would silently
  desync client/server name validation). The Appearance generator used to
  carry a second such invariant against a frontend `SeededRng`/
  `generateAppearance`; the pixel-sprite rewrite retired it — `sprite.ts`/
  `PlayerSprite.tsx` in `wunderkind-app` are pure rendering with no
  generation/RNG code, so there is currently no frontend counterpart for
  `AppearanceGeneratorService` to stay bit-identical with.
- **`SyncService` overwrites, not accumulates, club balance.**
  (`services.md`) A client resync applies `Club::setBalance()` as an
  absolute value. Combined with `SyncRecord.isRollback` tracking
  (`entities.md`) and `SyncRecordRepository::countRollbacksByClub`
  (`schema.md`), this is the backend's whole anti-cheat model for the
  client-authoritative sync design the root `README.md` describes — it's
  implemented as "detect and record," not "prevent."

## Flagged, not yet resolved

`.context/stages/05_ui/output/design-system.md` flags a remote script
(`perf-analytics.lndo.site/widget/...js`) loaded on every admin page via
`templates/bundles/EasyAdminBundle/layout.html.twig`. `.lndo.site` is
normally Lando-local-only, so this being checked into a template that
ships to production is unresolved and worth a human decision — noted
here again since it's the kind of thing a future agent touching the
admin layout should see before assuming it's intentional.

## World pack sets: how a player's world is actually built (2026-11-17)

**Read this before touching anything named `WorldPack*`, `StarterPack*`, or
`/api/initialize/*`.** It is the one place the whole flow is written down end to end, because
the pieces live in four stages and the two generation paths are deliberately different.

### What a set is

A **`WorldPackSet`** is ONE generation of ONE country's world:

- a **starter squad** (`world_pack_set.starter_pack_json`) — the players, staff and scouts a
  founding club is given; and
- a **payload per league tier** — `country_world_pack_cache` rows, FK'd to the set.

A set is claimed by **exactly one club** and never served twice, so every player who starts a
game gets a world nobody else will ever see. Each live country holds a buffer of
**`StarterConfig::$worldPackSetTarget`** claimable sets (default 2).

`WorldPackSetStatus`: `building` → `ready` → `claimed`, or `failed`. **Only `ready` is
claimable, and three kinds of `ready` row still are not**: one at a stale `payload_version` (the
claim filters on it), one already claimed, and one with `starter_pack_json IS NULL` — the shape
the introducing migration backfilled around pre-existing cache rows so `set_id` could become
NOT NULL without discarding a warm payload. Those are inert by design.

### Everything is GENERATED. Nothing is drawn from the shared pool.

Both the NPC clubs and the founder's own squad come from
**`WorldPackClubGenerationService`** — fresh, in memory, never persisted as pool rows:

| | Built by | From |
|---|---|---|
| NPC club squads | `WorldPackClubGenerationService::generatePlayers/Staff/Scouts()` | `StarterConfig::$npcSquadConfig` for the tier |
| Founder's squad | `StarterPackService::generatePack()` → the same three methods | `StarterConfig`'s `starter*Count` fields, mapped into the same tier-config shape by `starterSquadConfig()` |

**Two mappings in `starterSquadConfig()` are decisions, not transcription**, and are where to
look before changing what a founding squad contains:

- **`playerMin` = `playerMax` = `starterPlayerCount`.** An NPC squad size is ROLLED between a
  min and a max; a starter squad is an authored, exact number an operator sets on the Starter
  config screen and expects to get.
- **`foreignPercent` = 0**, where an NPC club uses its tier's configured percentage. The draw
  this replaced asked for home-nationality players and reached for foreigners only to cover a
  SHORTFALL; generation has no shortfall, so the faithful equivalent is all natives — and a
  founder fielding a home squad, with signing abroad as something the player does later, is the
  intended starting position.

`generatePack()`'s third argument is the club's reputation, used only for wage pricing. It is
**0** for a pre-generated set (no club exists yet) and the club's own value from
`initialize()` — also 0 for a brand-new club, which is what `Club::$reputation` defaults to. At
the shipped reputation spread of 0 it has no effect; once a spread is authored it redistributes
a tier's wage bill downward for a founder, which is the intended direction.

**One shared population is still READ, and it is not a pool draw:** `Agent`.
`agentRepository->findAll()` feeds `selectBoundedAgentPool()`, and the chosen subset is
referenced by the generated players via `assignAgents()` — referenced, never consumed or
deleted, so one agent legitimately represents players across many clubs and many packs.
`StarterConfig::$worldPackPlayersPerAgent` is what stops the distinct agents in a pack scaling
with the whole agent table.

So a set consumes nothing (verified: generating two full EN sets left `player`/`staff` row
counts unchanged) and **cannot be short of anything**. `InsufficientStarterPoolException` and
`InitializeController::MIN_POOL_SIZE` are both gone from this flow — a new game can no longer be
refused for a thin pool, which was the one failure in it with no client-side escape.

**The tier band is a POTENTIAL range, not an ability range.** `resolveAbilityRange()` feeds
`PlayerGenerationService::generate()`'s `$potentialRange`; `currentAbility` is the mean of
attributes drawn under `abilityTarget x potential` and lands BELOW the band. The name says
otherwise and it has misled before. Measured on EN tier 8 (authored band 2-7): both the founder's
squad and its NPC opponents read ability 1-3-5/6, potential 2-7 — one band, one basis. Until
2026-11-17 the founder's squad was DRAWN with `currentAbility BETWEEN band`, so it had its
ability where its opponents had their potential, which made founders systematically stronger than
their own division.

### TWO generation paths, and they work differently on purpose

This is the part most likely to be got wrong by assuming symmetry.

**1. Admin regenerate — queued, per-club, progress-tracked.**
`WorldPackGenerationOrchestrator::startRun($country, $tiers, $setCount)`:
generates each set's squad inline up front → persists the sets `building` → creates one
`WorldPackGenerationTierRun` per **(set x tier)** → dispatches one `WarmWorldPackTierMessage`
each on the **`worldpack`** transport. The tier handler then creates one
`WorldPackGenerationClubRun` per NPC club and dispatches `WarmWorldPackClubMessage` per club.
`WorldPackTierAssemblyService::maybeAssemble()` folds a tier's clubs into a payload once they
are all terminal, and `maybeReadySet()` flips the SET to `ready` once all ITS tier runs are
done. Clears the country's **unclaimed** sets first; claimed sets are spared, because a device
mid-initialisation still has tiers to fetch.

**2. Top-up — inline, one whole set, no run rows at all.**
`WorldPackSetTopUpService::topUp($country)` on the **`worldpackset`** transport: generates the
squad, loops the tiers calling `WorldInitializationService::buildTierPack(null, …)` with an
`em->clear()` between each, writes each payload, marks the set `ready` itself. It creates **no
`WorldPackGenerationRun`**, which is why the admin's `run-status` poll cannot see it and
`set-status/{country}` exists instead.

**Why not one path?** `world_pack_generation_run` has a partial unique index enforcing one
active run per country, and a top-up fires on **every game initialisation** — so routing
top-ups through the run table would make that index reject the common case rather than a race.
It would also queue a country's whole per-club fan-out — 100+ DB-heavy messages, the same
figure that got `worldpack` split off `async` in the first place — in front of an admin who just
pressed Regenerate, as ROUTINE restocking. Hence a third transport. `app:worldpack:warm` uses path 2
(`rebuild()` = clear unclaimed, then build N unconditionally).

### The claim, and why it is per-SET rather than per-tier

- `POST /api/initialize/starter` claims a whole set
  (`WorldPackSetService::claimForClub()`) and writes `club.world_pack_set_id` +
  `club.starter_pack_json` in one flush.
- `POST /api/initialize/league/{tier}` serves the tier **out of that club's claimed set**.

Claiming the whole set at step one is **load-bearing, not tidiness**: a device's eight tiers must
come from one generation run (same fixtures, same NPC ids). Claiming per tier would satisfy
"unique" and still hand out an incoherent world.

**A served tier is STAMPED, not deleted (2026-11-23).** Owner: *"we should never get mixed
worlds"*, and once a pack is claimed it lives on the device only. Both hold like this:

- `tier()` reads the club's set with `readClaimedTier()` (any version: a claimed set is one world,
  so a version bump mid-load still serves the rest of THAT world) and stamps the row's
  `served_at`. A retried or restarted load replays the same bytes, because the client treats an
  empty or truncated 2xx as retryable and the app's own client assumes replay.
- The club is world-initialised once every row of its set is served (`allServed()`).
- `app:worldpack:maintain` clears the rows of worlds that finished loading more than
  `FINISHED_WORLD_GRACE` (1 hour) ago, so a finished save leaves nothing on the server.
- A claimed set missing a tier answers **410 `world_pack_tier_unavailable`** and logs at ERROR. It
  is never rebuilt inline, because a rebuilt tier is another generation.
- Admin delete-all spares a world a device is still loading.

⚠️ **Do not go back to delete-on-serve** (`df3cae1`, 2026-11-22, reverted the next day): a lost
response or a restarted load then rebuilt the tier from a fresh generation, a mixed world.
`InitializeSetClaimTest` pins the replay.

The claim is **one SQL statement** —
`UPDATE … WHERE id = (SELECT … FOR UPDATE SKIP LOCKED) RETURNING id`. Select-then-update hands
the same set to two clubs whenever two devices initialise within milliseconds, which is the one
guarantee this whole mechanism exists to provide. `claimForClub()` also does a **reconcile
read** first: the claim commits on its own and the club-side write is a separate flush, so
without it a failure between the two would leak a set and claim a second.

**The fallback is what keeps this backwards compatible.** A country with no stock generates the
squad on the spot and builds each tier inline, **uncached** (nothing is written, because a row
would need a `(country, tier)` key — exactly the ambiguity sets removed). It must never become
an error: a new refusal here is unrecognisable to a shipped build and the init flow has no
client-side escape.

### Keeping the buffer stocked

- Every `POST /api/initialize/starter` dispatches `TopUpWorldPackSetMessage` — gated on
  `needsTopUp()` so a healthy country writes nothing to the queue, and wrapped so a transport
  failure can never fail an initialisation.
- 🔴 **NONE OF THE ASYNC MACHINERY IN THIS SECTION ACTUALLY RUNS ON THE DEV STACK** (established
  2026-11-20 by elimination). Dispatch succeeds, `crond` is running, `deficit()` is version-aware,
  the write path stamps the version — **and nothing is ever written.** Cause unknown; the defect is
  in the consumer or handler on that stack, not in the generation code. **`php bin/console
  app:worldpack:warm <CC>` builds inline and works.** Read the rest of this section as the design,
  not as what you will observe on dev, and see `01_overview/output/deployment.md` for the two
  operational traps (this one, and the admin rebuild sparing claimed sets) before concluding
  anything from a device reading.
- `app:worldpack:maintain` (hourly, `docker/worldpack-maintain.sh`) is the backstop for that
  best-effort dispatch, and the only thing that restocks after a deploy or a
  `WORLD_PACK_VERSION` bump — which makes **every existing set unclaimable at once**, since the
  claim filters on the version. It queues rather than generates, so one slow country cannot
  stall the sweep. It also **prunes** the tier payloads of sets claimed longer ago than
  `StarterConfig::$worldPackClaimedRetentionDays` (default 7), keeping the set row as the record
  of which club got which world. It is only the backstop for abandoned initialisations: a world
  that finishes loading is cleared by the same command an hour later (`FINISHED_WORLD_GRACE`).
- Live countries come from `CountryProfileRepository::liveCountryCodes()`. **On a box where no
  country profile is LIVE this sweep tops up nothing and the admin SET STOCK table is empty** —
  that is correct, not broken.
- Guards against stacking: a dispatch-side gate, a deficit **re-read on drain**, and a
  per-country `pg_try_advisory_lock`. `countBuildingSince()` is **time-bounded** (30 min)
  because an unbounded count lets one crashed worker suppress every future top-up for its
  country permanently.

### Traps — each of these has already cost something

1. **Never look a payload row up by `(country, tier)`.** It stopped being unique when a country
   began holding a buffer. `findForCountryAndTier()` was deleted for this reason and must not
   come back; reach a row through its set. The damage would be silent — tier 1 of one world and
   tier 2 of another, every response a 200.
2. **The tier run's unique key needs its partial companion.** `(run_id, set_id, tier)` does not
   constrain set-less rows at all, because Postgres treats NULLs as DISTINCT. Hence
   `uq_..._run_tier_no_set` on `(run_id, tier) WHERE set_id IS NULL`.
3. **…and that forces the tier run's FK to CASCADE.** With `SET NULL`, deleting a country's
   stock nulls several tier runs sharing a `(run_id, tier)` and they collide on that partial
   index — so an ordinary admin "delete this country's sets" fails with a unique violation
   raised from inside the FK. Changing either choice back reintroduces the other's failure.
3b. **Staff AND SCOUTS are generated per tier (staff 2026-11-17, scouts 2026-11-18).**
   "Staff" means both — an owner ruling, recorded in `tribal-knowledge.md` — but a `Scout` is a
   separate entity with its own generator, so a fix that follows `StaffRole` skips it. That
   happened once already. Scout EXPERIENCE is banded through
   `ScoutGenerationService::build()`. It matters more than it looks because the app splits a
   club's wage reservation in proportion to ability, so one out-of-band member takes most of
   the budget. The legacy role band is scaled by `Economy\TierBandScaleResolver`.

   🔴 **CORRECTED 2026-11-20 (`a818a60b`): STAFF ABILITY NO LONGER COMES FROM THE CLUB'S BAND, AND
   `StaffGenerationService::build()`'s `$abilityRange` PARAMETER IS GONE.** This entry described
   the workaround, which was the other half of the defect A25 removed: `StarterPackService` and
   both world-pack generators filled that parameter with the squad's **PLAYER** band, and that
   table is **POTENTIAL**. A staff member has no current/potential split, so a coach handed a
   potential band sits AT that figure while the players beside him sit well below it — both sides
   of every staff-vs-player comparison were different quantities. Both generators now take
   `(country, tier)` and resolve the band themselves through **`StaffAbilityRangeService`**, the
   only reader of `pool_config.staff_ability_ranges`, whose numbers are on the player **current
   ability** scale. ⚠️ **The parameter was REMOVED rather than defaulted to null**, deliberately:
   a caller that can pass a band can pass the wrong one, and leaving the hole open is how the
   workaround survived this long. `scouts[]` are covered by the same move — the banded input is now
   the staff table, though a scout's `ability` remains derived from experience and judgements.
   `WORLD_PACK_VERSION` 13 → 14 is what delivers it, since a generated staff member exists nowhere
   but in the pack that holds it. See `04_interfaces/output/services.md` and
   `03_data/output/migrations.md`'s `Version20261120150000`. Before that a tier-8 club paid ~£1,000/wk
   in staff against ~£165/wk for its whole squad. The scaling is applied to the BAND, never
   inside `legacyStaffPence()`, which is pinned byte-for-byte by `WageModelLegacyParityTest`.
4. **Never price a player inside `PlayerSnapshotSerializer`.** `WorldPackSnapshotBuilder`
   delegates to it, and the pack is a frozen cached surface gated by `WORLD_PACK_VERSION`;
   re-pricing there changes cached content on every read with no version bump. Shared-pool rows
   go through `Economy\ServeTimePlayerPricer` at serve time. See `tribal-knowledge.md`'s owner
   ruling.

   ⚠️ **UPDATED 2026-11-18 (v11) — the trap is unchanged, the second half of it is gone.**
   "Generated populations are priced once at generation" is no longer true: **nothing is priced
   at generation.** A pack carries no money at all — `contractValue`, `agent.commissionRate`,
   staff `weeklySalary`, club `startingBalance`, `land.valuePence` and the four league money
   keys (`tvDeal`, `sponsorPot`, `prizeMoney`, `leaguePositionPot`) have all left it. The device
   prices the squad it ingests from published config.

   The rule in bold above matters MORE after that change, not less, and in a way that is easy
   to get backwards: the obvious way to take a price out of a pack is to delete it from the
   serializer, and that would silently un-price every `/api/scout/search`,
   `/api/players/foreign` and `/api/players/youth` row — those surfaces are priced at serve
   time and need the key. So the money is stripped at the PACK BOUNDARY, in
   `WorldPackSnapshotBuilder::buildPlayerSnapshot()`, which is the one seam that satisfies both
   constraints. `WorldPackCarriesNoMoneyTest` pins the invariant from the pack side and pins
   that the shared serializer still prices from the browse side.
5. **`down()` on the introducing migration is not lossless and cannot be.** `uq_country_tier`
   cannot coexist with a buffer, so the rollback keeps each country's oldest set and drops the
   rest. That is the rollback, not a side effect.

### What the shared player pool is still for

Nothing in this flow. It feeds `GET /api/players/youth`, `GET /api/players/foreign`,
`GET /api/scout/search` and market data, and those are the demands its floors should be sized
against. **`StarterPackDiagnosticService`, `StarterPoolAuditService`, `app:starter-pack:check`
and `app:starter-pool:audit` all still turn on "can a starter pack be drawn for this country",
which is now always yes** — their verdict means nothing for starter packs. They were kept
anyway, because `app:starter-pool:audit` is the only SCHEDULED thing that tops the pool up at
all (`app:pool:warm` is the general warmer and is unscheduled) and deleting it would quietly
starve those four surfaces. Their aim needs re-deriving; until then they over-queue rather than
under-, which is the safe direction.

### Where the code is

| Thing | File |
|---|---|
| Set entity / status / repo | `src/Entity/WorldPack/WorldPackSet.php`, `src/Enum/WorldPack/WorldPackSetStatus.php`, `src/Repository/WorldPack/WorldPackSetRepository.php` |
| Claim, buffer maths, ready/failed | `src/Service/WorldPack/WorldPackSetService.php` |
| Admin regenerate (path 1) | `src/Service/WorldPack/WorldPackGenerationOrchestrator.php`, `WorldPackTierAssemblyService.php`, `src/MessageHandler/WorldPack/*` |
| Top-up (path 2) | `src/Service/WorldPack/WorldPackSetTopUpService.php`, `src/MessageHandler/WorldPack/TopUpWorldPackSetMessageHandler.php` |
| Tier payload read/write | `src/Service/WorldPackCacheService.php` (set-scoped only) |
| Founder's squad | `src/Service/StarterPackService.php::generatePack()` |
| Endpoints | `src/Controller/InitializeController.php` |
| Admin screen | `src/Controller/Admin/WorldPackController.php`, `DashboardController::worldpackCache()`, `templates/admin/worldpack_cache.html.twig` |
| Commands | `app:worldpack:warm` (rebuild buffer; `--tier` is a hard error), `app:worldpack:topup` (+1, clears nothing), `app:worldpack:maintain` (sweep + prune) |
| Migration | `migrations/Version20261117120000.php` |

## Authority: the device owns club state, the backend owns the world

The single most load-bearing boundary in this codebase, and the easiest to
misread from the schema alone.

**Device-owned, mirrored into the backend by `POST /api/sync` only.** A club's
`balance`, `totalCareerEarnings`, `reputation`, squad, staff, facilities and
weekly progression. `SyncService::process()` assigns these from the request
absolutely rather than applying deltas, so the backend's copy is a snapshot of
what a device last reported — never a value the backend computed or can repair.
Two consequences:

- A server-side write to a mirrored field is **transient**. It is replaced on
  the next sync. Anything that must stick has to be enforced on the device.
- Deleting backend club-scoped rows loses history and derived artefacts, **not**
  the player's game.

**Backend-owned.** World data (leagues, NPC clubs, market pools, world packs),
cross-club aggregates no single device could compute (`LeaderboardEntry`,
`UserLedger`, `SeasonRecord`, transfer leaderboards), operator-authored content
(`AdminMessage`, narrative templates, excursions), and competition/friendly
*structure* — brackets, fixtures, results, rounds. Note the split inside
competitions: the backend owns the bracket and resolves matches, but each
entrant's submitted squad is a client snapshot it stores verbatim and never
recomputes.

**Why this is stated here rather than inferred:** during the 2026-10-28
tournament work a code review flagged that truncating competition entrants
destroyed entry fees "already deducted from `club.balance`", and that was
carried as a release blocker until the human pointed out that club balance only
matters on the device. The reasoning looked sound and was wrong at the premise —
a `balance` column in this schema does not imply the backend owns that balance.
Check which side of this boundary a field sits on before reasoning about losing
or correcting it.
