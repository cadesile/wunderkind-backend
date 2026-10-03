# CLAUDE.md


<!-- context-generator: start -->
## Project Context (ICM)

`.context/` is the source of truth for this codebase's structure, stack,
data model, and interfaces — read `.context/CONTEXT.md` first; it routes
you to the stage relevant to your task.

**Before exploring this codebase for ANY task** — including spawning a
sub-agent, grepping, or reading manifests/config/source files directly —
check `.context/CONTEXT.md` and the relevant stage's `output/` for
the answer first. This applies even when the task isn't phrased as an
architecture question (e.g. "check dependencies for security updates"
still needs facts a stage may already document). Only explore raw source
for what's missing or possibly stale.

Read and follow `.agents/skills/icm-codebase-context/SKILL.md` for how to use and maintain `.context/`.

**If this is the first time you (any agent) are reading this file in a
session:** tell the human `.context/` and this skill are available,
before doing anything else. If `.context/stages/*/output/` is empty
or missing, explicitly ask whether to run stage `01_overview` now, in this
session, to set it up (see the skill's CONTEXT.md) — don't just silently
skip it and don't silently run it unasked either.

You (the human) can also say "warm", "warm the context", or
`/icm-context warm` at any time to trigger this explicitly, without
waiting to be asked — see `SKILL.md`'s Triggers table.

**Finishing any task:** if your change affects a stage's documented content
(schema/migration → `03_data`, new routes/services → `04_interfaces`,
new module/directory → `02_architecture`, etc.), update that stage's
`output/` as part of finishing the task — not as a separate step.

This file is a disposable, regenerable pointer (see .gitignore) — `.context/`
is the actual source of truth. If you ever find yourself in this repo
without a file like this one, but `.context/` exists, treat it as
authoritative anyway and recreate this pointer for whichever agent you are.
<!-- context-generator: end -->

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Dev Environment

All PHP commands must run inside the Lando container:

```bash
lando start                          # spin up PHP 8.4 + PostgreSQL 16
lando php bin/console <command>      # Symfony console
lando composer <command>             # Composer
lando psql                           # PostgreSQL shell (db: wunderkind, user/pass: wunderkind)
lando logs -s appserver              # tail app logs
```

**Database**: PostgreSQL 16. Connection string:
`postgresql://wunderkind:wunderkind@postgres:5432/wunderkind?serverVersion=16&charset=utf8`

**Developing against real data volume**: `bash scripts/pull-prod-db.sh` pulls a fresh
`pg_dump` from the production Hetzner box over SSH and restores it into the local Lando
database, replacing whatever's there. **Raw, not scrubbed** — includes real user PII
(emails, owner identity, GDPR `DeletionRequest` rows) by deliberate choice; see the
script's own header banner before reusing it beyond solo local dev. Requires SSH access to
prod and Lando running.

## Common Commands

```bash
# Tests
lando php vendor/bin/phpunit --no-coverage                              # full suite
lando php vendor/bin/phpunit tests/Service/SyncServiceTest.php --no-coverage  # single file
lando php vendor/bin/phpunit --filter testMethodName --no-coverage      # single test

# Cache
lando php bin/console cache:clear

# Database — fresh setup on a new clone
lando php bin/console doctrine:database:drop --force
lando php bin/console doctrine:database:create
lando php bin/console doctrine:schema:create
lando php bin/console doctrine:migrations:sync-metadata-storage
lando php bin/console doctrine:migrations:version --add --all --no-interaction

# Generate JWT keys (once, or after key rotation)
lando php bin/console lexik:jwt:generate-keypair --no-pass --overwrite

# After adding/changing entities, generate a new migration
lando php bin/console doctrine:migrations:diff

# Seed data (run in this order on a fresh DB) — matches the deploy workflow's seeder
# sequence (.github/workflows/deploy-dev.yml); missing any of these behaves like the
# real feature is broken (e.g. skipping app:seed-match-narrative makes every
# Competition result's narrativePayload come back empty, not an obvious "seed this" error)
lando php bin/console app:seed-game-events        # narrative event templates
lando php bin/console app:seed-match-narrative    # MATCH_NARRATIVE chain-graph content (Competition result narrativePayload)
lando php bin/console app:seed-player-events      # player event templates
lando php bin/console app:seed:morale-events      # morale event templates
lando php bin/console app:seed-archetypes         # 20 curated archetypes (10 positive, 10 negative)
lando php bin/console app:seed-social-post-templates  # social media post templates
lando php bin/console app:seed-excursions         # team-bonding excursion catalogue
lando php bin/console app:generate-market-data    # agents, scouts, investors, sponsors
lando php bin/console app:market:generate         # generate market pool entities
lando php bin/console app:pool:warm               # pre-warm the market entity pool
lando php bin/console app:worldpack:warm          # warm country/nationality data cache

# Admin
lando php bin/console app:admin:create            # create a backend admin user

# Debug
lando php bin/console debug:router
lando php bin/console debug:firewall
```

## Testing

- Unit tests (`PHPUnit\Framework\TestCase`) run in-memory and need no DB.
- **Functional / `WebTestCase` / `KernelTestCase` tests use a SEPARATE database, `wunderkind_test`** (env `test`, which skips `.env.local` — so it does **not** share the dev DB). This DB is created via `doctrine:schema:create`, not migrations, so its migration metadata starts empty and it **drifts** whenever a migration adds a column that `schema:create` didn't originally build. Symptom: `column "x" does not exist` errors in functional tests only. Reconcile it:
  ```bash
  # See the drift, then apply missing columns to the test DB:
  lando php bin/console doctrine:schema:update --dump-sql --env=test
  # After the schema matches entities, mark all migrations applied so metadata is truthful:
  lando php bin/console doctrine:migrations:sync-metadata-storage --env=test
  lando php bin/console doctrine:migrations:version --add --all --no-interaction --env=test
  # Verify:
  lando php bin/console doctrine:migrations:up-to-date --env=test
  lando psql -d wunderkind_test -c "<sql>"   # inspect the test DB directly
  ```
  **This doesn't catch everything.** `doctrine:schema:update`/`schema:create` only
  reconcile what's declared in Doctrine ORM mapping attributes. Two kinds of
  drift are invisible to it and need a manual, one-time fix per test DB:
  - **Raw-SQL partial unique indexes** (Postgres `CREATE UNIQUE INDEX ... WHERE
    (...)`, e.g. `uq_active_competition_one_open_per_template`,
    `uq_entrant_reward_claim_with_template`/`_without_template` from
    `Version20260916112510`) aren't representable as ORM metadata, so
    `schema:create` never builds them. Code that does `INSERT ... ON CONFLICT
    DO NOTHING`/`ON CONFLICT (col) WHERE (...) DO NOTHING` against one of these
    (e.g. `CompetitionProvisionInstancesCommand`, `RewardApplierService`) will
    silently insert duplicate rows in tests instead of no-op'ing — symptom:
    "actual size N matches expected size 1"-style count assertions failing, or
    a `PDOException: ... no unique or exclusion constraint matching the ON
    CONFLICT specification`. Fix: re-run the `CREATE UNIQUE INDEX ...` statement
    from the relevant migration directly against `wunderkind_test` via `lando
    psql -d wunderkind_test`.
  - **Seed commands aren't run against `wunderkind_test` by anything.** If a
    functional test's behavior depends on seeded rows (e.g.
    `MatchNarrativeGeneratorService` reading `MATCH_NARRATIVE`-category
    `GameEventTemplate` rows seeded by `app:seed-match-narrative`), a fresh
    `wunderkind_test` has none — symptom: an assertion like "must carry a
    non-empty narrative timeline" failing with an empty array, not an obvious
    missing-data error. Fix: run the relevant seed command with `--env=test`.
- **API functional-test login is single-use.** `$client->loginUser($user, 'api')` authenticates
  **exactly one request** against the stateless JWT firewall — the next request on the same
  client returns `401 JWT Token not found`, and calling `loginUser()` again does not recover it.
  The test env has no JWT keys (`JWT_SECRET_KEY` is empty in `.env`, and `.env.local` is skipped
  under `APP_ENV=test`), so minting a real bearer token is not an option either. To exercise more
  than one authenticated call, reboot per request: `self::ensureKernelShutdown()`,
  `static::createClient()`, re-fetch the `User` from the fresh EntityManager, then `loginUser()`.
  See `AdminMessageControllerTest::authenticatedRequest()`. This is why
  `InboxControllerTest` only ever asserts 401.
- **Admin functional-test login** — the `admin` firewall uses a Doctrine `EntityUserProvider` that re-fetches the user by email on every request, so an in-memory-only `Admin` is silently treated as unauthenticated. Persist a real `Admin` first, then `$client->loginUser($admin, 'admin')` (see `tests/Controller/Admin/SocialAuthControllerTest.php`).

## Git Workflow

`development` is the working branch for most tasks — commit directly there
(it's a direct child of `master`). Never commit directly to `master` or
`dev`; those are only ever reached by merging `development` in.

```bash
git checkout development
git pull origin development
# ...work, commit directly on development...
git push origin development
```

**Three branches: `development` → `dev` and `development` → `master`, propagated by merge,
never cherry-pick.** When a task is complete: merge `development` → `dev` and push (external
dev/staging testing), then merge `development` → `master` and push (production release) — two
**separate** merges, in that order. `dev` can sit ahead of and diverge from `development`/
`master` for a long time, so those merges aren't always clean. Full policy (propagation rules,
verify-before-push, conflict handling): `.context/stages/01_overview/output/deployment.md`.

## Deployment

Full runbook: `docs/deploy/hetzner.md`. Context digest (topology, secrets, gotchas,
per-branch deploy table, **and the git branching/merge policy**):
`.context/stages/01_overview/output/deployment.md`.

## Architecture

### The Hybrid Model
The server is **not** the game engine. Gameplay (Weekly Tick, training, aging, personality) runs entirely on-device. The API handles:
1. **Club sync** — receives aggregate deltas, updates `Club` totals
2. **Anti-cheat** — rejects `weekNumber` rollbacks; every sync recorded in `SyncRecord` even if invalid
3. **Leaderboards** — upserts `LeaderboardEntry` rows for `all-time` and current ISO week
4. **World data** — serves league structures, NPC clubs, and market entities to clients

### Pool Lifecycle (Player + Staff)
The backend is a **data pool**. `Player` and `Staff` entities have **no club FK** — they exist in the DB as available pool data only. When a Player or Staff is consumed (starter pack or market assign), it is **immediately deleted** from the DB and a full snapshot is returned to the frontend, which stores it locally. The backend never tracks which players/staff a club owns.

- `Player`/`Staff` in the DB = available pool. No `club_id`, no `assigned_at`.
- Snapshots are built via `WorldInitializationService::buildPlayerSnapshot()` / `buildStaffSnapshot()` **before** the entity is removed.
- `Sponsor` and `Investor` are **not** pool entities — they retain a `club_id` FK (financial contracts).
- `Scout` has no club FK and is not deleted on assign.

### Request Flow (POST /api/sync)
```
JWT firewall → SyncController::sync()
  → #[MapRequestPayload] → SyncRequest DTO
  → SyncService::process()
      → ClubRepository::findByUser()
      → persist SyncRecord (always)
      → anti-cheat check → 409 if week < lastSyncedWeek
      → update Club aggregates + manager trait shifts
      → LeaderboardEntryRepository::findOrCreate() × 6
      → EconomicService: financial year-end + sponsor check
      → flush → return JSON
```

### World Initialization Flow
When a client first boots (`POST /api/club/initialize`):
```
ClubController → ClubInitializationService::initializeClub()
  → creates Club, sets paName + manager traits
  → StarterPackService::initialize() → builds snapshots, deletes consumed Player/Staff from DB
  → LeagueService::assignClubToStarterLeague() → places club in country/tier league
  → WorldInitializationService::buildLeaguesPack() → serializes full league pyramid
  → WorldInitializationService::buildTierPack() → NPC clubs + fixtures for the club's tier
```

### Avatar Appearance (backend-owned)
`Player`, `Staff`, `Scout`, `Agent`, `User` each carry a nullable `appearance` json column holding the pixel-sprite `SpriteConfig` shape (15 keys, always all present regardless of role: `hair, hairColor, headband, skin, face, facial, lip, primary, secondary, kit, shorts, socks, outfit, trousers, glasses`). This replaced an earlier, structurally different 10-key design (`skinTone, hairStyle, ..., jerseyVariant`) — if you see that shape anywhere (old docs, old test fixtures, a stale row that predates a `--force` backfill), it's the previous design, not a variant of the current one. The backend owns generation:
- `AppearanceGeneratorService` produces the 15-key config from `(id, role, age, nationality?)`, deterministic by id. **No cross-repo RNG-stream bit-parity is preserved with any frontend generator** — unlike the design this replaced, the sprite's own drawing logic (`sprite.ts`/`PlayerSprite.tsx`, from `wunderkind-app`) is pure rendering with no generation/RNG code, so there is currently no frontend counterpart to stay bit-identical with.
- **Every entity stores all 15 keys, regardless of role** — Player gets meaningful `kit`/`shorts`/`socks` and fixed defaults for `outfit`/`trousers`/`glasses`; Staff/Scout/Agent (all three render as the sprite's `'staff'` body shape) get the reverse. This mirrors the sprite's own documented convention that a saved config may hold both groups' keys harmlessly, since each `type` ignores the other's.
- **Skin is an id, not a hex.** `App\Enum\Appearance\SkinId` (`s1`..`s6`, lightest→darkest) carries two hex shades per case (`baseColor()`/`shadeColor()`) — the sprite draws a base fill plus a darker shade for ears/neck/brows, unlike the old single-hex `SkinTone`. `App\Enum\Appearance\WorldRegion::skinWeights()` is nationality-weighted exactly as before, just re-keyed to `SkinId` values (same 9 regions, same weight numbers — the light→dark ordering carried over 1:1).
- **`hair` is weighted by age band, not uniform.** `AppearanceGeneratorService::hairStyleWeights(int $age)` returns a `HairStyle`-keyed percentage table (three bands: ≤35, 36–45, 46+), same shape/style as `WorldRegion::skinWeights()` — `mohawk`/`afro`/`cornrows` stay low (≤7%) at every band and taper further with age, in favour of practical short styles; every style keeps a nonzero chance rather than being excluded outright past a cutoff. `facial` (facial hair) is age-gated per role via `rollFacialHair()`: staff/scout/agent unchanged (20+, 60% chance of stubble/beard); **players are newly eligible too** (25+, 35% chance) — previously hardcoded to `'none'` unconditionally. `headband` is role-split too: staff/scout/agent keep the base 20% chance, but **players roll it at 1-in-1000** — a deliberately vanishing-rare cosmetic, not an even split.
- `hairColor`, `lip`, and `primary`/`secondary` (`KitColor`, one shared 12-color palette for both player kits and staff outfits — no more vibrant-player-vs-muted-staff split) are hex-backed enums, same pattern as `SkinId`'s predecessor. **`hairColor` is weighted** (`AppearanceGeneratorService::hairColorWeights()`, same `HairColor`-keyed-percentage-table style as `hairStyleWeights()`) — `GINGER` (orange) is deliberately rare (5%); the age>45 grey-forcing chance still runs first and short-circuits this entirely. **`lip` is skin-linked, not independent:** `SkinId::S5`/`S6` (dark skin) always get the reserved `LipColor::DEEP_BROWN` (`#5c3822`) — every other skin tone picks uniformly from the rest of the palette, and never draws `DEEP_BROWN` via generation (it's still a valid manual admin choice for any entity).
- `face` is a fixed, generated-once expression (`App\Enum\Appearance\Face`, 9 cases) — a pure cosmetic pick, **not** tied to morale or personality. This is a deliberate simplification versus the old design, where eyes/mouth were computed live from morale at render time; the sprite has no such live coupling. **Players always default to `neutral`** — only staff/scout/agent get a varied pick — in both the generator and the admin widget's Randomise button.
- `AppearanceLifecycleSubscriber` (Doctrine `prePersist`) auto-fills `appearance` for any of the five entity types persisted without one, passing `getNationality()` through, so **every** creation path is covered centrally (don't hook individual construction sites). For `User` this only ever gives a default staff-shaped avatar at registration (see Owner Identity below for how the real account holder customizes it afterward). `app:backfill-appearances` fills pre-existing null rows; `app:backfill-appearances --force` additionally **unconditionally regenerates every row**, overwriting whatever it had — this is the one-time migration mechanism when the Appearance shape itself changes (as it did here), not a targeted single-field refresh. Both passes stream via `Query::toIterable()` with periodic `$em->clear()` rather than loading the full table at once (this command previously OOM'd production on ~36.5k rows via `findBy`/`findAll()` and is deliberately excluded from the post-deploy sequence — see `docs/deploy/hetzner.md`; run it manually).
- `appearance` is emitted verbatim (a passthrough of `getAppearance()`) in every player/staff/scout/agent serializer — `buildPlayerSnapshot`/`buildStaffSnapshot`/`buildScoutSnapshot`, `MarketDataService::serialize{Coach,Scout,Agent}`, and `ScoutSearchController::serializePlayer`. All four are schema-agnostic passthroughs of the whole JSON blob — none of them needed to change for this rewrite, and none would need to change for a future one either.
- The admin edit form (`AppearanceType`) renders identically for all 15 keys on every entity type, but takes a `person_type` (`'player'`|`'staff'`) form-type option — set per CrudController via `->setFormType(AppearanceType::class)->setFormTypeOptions(['person_type' => ...])` (this EasyAdmin version's `setFormType()` takes only the class name; options are a separate chained call) — that only decides which body shape the live preview draws and which of the kit/shorts/socks vs outfit/trousers/glasses sections the widget shows; it does not change what's stored. `public/assets/avatar-compositor.js` is a mechanical plain-JS port of `sprite.ts`'s `buildGrid()`/`toRuns()` (do not hand-reinterpret the pixel math — port it verbatim if the sprite's drawing logic ever changes) and also exposes `window.SKIN_SHADES` for the widget's skin swatches.

### Sync v2: In-Club Player/Staff Appearance (client-authoritative)
Distinct from Avatar Appearance above, which is backend-generated at pool stage: once a
player/staff is signed into a club, their `Player`/`Staff` row is deleted (pool-consumption —
see Pool Lifecycle above), so there is no backend row left to hold an evolving in-game
appearance. `POST /api/sync`'s `playerStats[]`/`staffStats[]` (sync v2, both additive/optional
— absent or `undefined` on older clients) report each currently-owned player's/staff
member's **client-authoritative** `appearanceConfig` back to the backend for storage, the
same trust model as `Club.homeKitConfig`/`badgeConfig` (client-supplied verbatim, no
server-side validation) — this is not generation, and `AppearanceGeneratorService` is not
involved.
- **`PlayerCareerStat.appearanceConfig`** (from `playerStats[]`) is **personal-traits-only** —
  `hair/hairColor/headband/skin/face/facial/lip`, no kit colors (`kit`/`shorts`/`socks`/
  `primary`/`secondary`), since a player's kit is club-derived and never sent per-player.
  **Kit color is determined from the parent club** (confirmed explicitly by the human,
  not an inferred default) — `PlayerCareerStat::toFullAppearanceConfig()` merges this
  personal-traits config with `$club->getHomeKitConfig()`'s kit colors into one complete,
  renderable sprite config. Not wired into any endpoint yet — groundwork for whenever a
  consumer (an admin view, say) needs to actually render this.
- **`StaffCareerProfile`** (from `staffStats[]`) is a **brand-new entity**, not an extension of
  `PlayerCareerStat` — there is no goals/assists/rating concept for staff, so don't try to map
  one onto the other. `appearanceConfig` here carries the full staff sprite shape (persists
  `outfit`/`trousers`/`glasses` too, unlike players' subset above). Keyed by client-generated
  `staffId` the same way `PlayerCareerStat` is keyed by `playerId` (Staff rows are pool-only
  and deleted on consumption, same as Player) — `UNIQUE(club, staffId)`, upserted every sync.
  `staffRole` is a **free string, not the `StaffRole` enum** — the client's role set is wider
  (e.g. `scout`, `assistant_coach` aren't `StaffRole` cases; `Scout` is a separate entity
  backend-side) — storing it typed would reject legitimate values.
- **Both `appearanceConfig` fields use "omitted key preserves, explicit `null` clears"
  semantics** (`array_key_exists`, not `??`, in `SyncService::processPlayerCareerStats()`/
  `processStaffCareerProfiles()`) — an older client that doesn't send the key at all must not
  wipe out a config a newer client already reported for that player/staff member. Neither
  field is copied onto `PlayerCareerStatSnapshot` (identity/cosmetic data, not a stat worth
  historizing across season resets).

### Owner Identity (User, backend-owned)
`User` carries `name`, `nationality`, `gender`, `dob`, and `appearance` (the same 15-key sprite shape as Player/Staff/Scout/Agent) — the real account holder's own identity, single per account and read live by every club that account creates via `Club::getUser()` (no per-club copy). This **replaces** two removed, redundant blobs: the ad hoc `User::$managerProfile` (previously set unvalidated from the raw registration payload) and the structured but never-serialized `Club::$managerProfile` (previously set once at club creation from `ClubInitRequest::$manager`/`ManagerProfileInput`). `Club::$paName` (the fictional in-game "player agent" persona) is unrelated and untouched.
- **`AppearanceRole::OWNER`** is the role passed to the generator for `User` — it falls into the existing non-player ("staff shape") branching with zero code changes needed inside `AppearanceGeneratorService` itself, same as COACH/SCOUT/AGENT.
- **`OwnerAvatarController`** (`ROLE_CLUB`) exposes both `GET /api/owner-avatar` (reads back the current `{name, nationality, gender, dob, avatar}`) and **`POST /api/owner-avatar`**, a **partial update**: only keys actually present in the JSON body are applied (checked against the raw request array, not just the DTO's nullable properties, since an omitted field and an explicit `null` are indistinguishable on a plain nullable property) — omitted fields are left untouched. Avatar resolution: a client-supplied `avatar` is stored verbatim (the app's own avatar-builder UI is the source of truth, no per-field validation); otherwise, if `nationality`/`dob` changed this call **and** the appearance is still null, one gets generated (first-fill only — changing `name` later never silently overwrites a saved custom look).
- `gender` is restricted to `male`/`female` (same `Assert\Choice` the old `ManagerProfileInput` used).
- Admin (`UserCrudController`) has an "Owner Identity" fieldset with the same `AppearanceType`/`person_type: 'staff'` widget wiring as `AgentCrudController` — EDIT is now enabled (previously fully disabled); NEW/DELETE stay disabled (accounts are created via registration and removed via account deletion, not admin).

### Centralized User Ledger (UserLedger, backend-owned)
Now that a single owner avatar (see Owner Identity above) persists across every club a `User`
owns, `UserLedger` (table `user_ledger`) centralizes a user's cross-club earnings instead of
leaving a manager's dividend trapped inside whichever single club's `totalCareerEarnings` it
happened to land on. Today it holds exactly one kind of entry — `UserLedgerEntryType::DIVIDEND_DRAW`
— detected server-side from the sync payload's free-form `ledger[]` array
(`SyncRequest::$ledger`, the same array `SyncService` already archives verbatim onto
`SyncRecord::$payload`): any entry with `category === 'dividend_draw'` is a client-reported
dividend draw against the syncing club.
- **`UserLedgerService::recordDividendDraws()` is the single writer.** Both `SyncService::process()`
  (every live sync) and the one-time `app:backfill-user-ledger` command (replays every historical
  `SyncRecord` for payloads predating this feature) go through it, so the balance-chaining logic
  below exists in exactly one place.
- **Every row snapshots `balanceBeforePence`/`balanceAfterPence`** — the user's overall centralized
  balance immediately before/after that entry (`balanceAfterPence = balanceBeforePence + amountPence`)
  — so the running total is reconstructible from history alone, not trusted to only the latest row.
  `UserLedgerRepository::getCurrentBalance()` reads the latest row's `balanceAfterPence`.
- **`dividend_draw`'s `amount` is real pence — the one `ledger[].category` that is NOT
  100x-inflated.** Every pre-existing category carries that inflation (a bug in the client's
  existing on-device ledger-writing engine — see `sync_record.payload` field conventions in
  `.context/stages/03_data/output/schema.md`), but `dividend_draw` is a brand-new category
  with no shipped client code to inherit it from, so it was deliberately specified as
  correctly-scaled from the start (explicit human decision, not inferred) — same reasoning as
  `promises[].offer.amountPence`. **Never** route it through
  `LiveTelemetryService::ledgerAmountToPence()`. See `docs/api/user-ledger.md` for the
  frontend-facing contract.
- **Idempotent per `(sourceSyncRecord, sourceLedgerIndex)`** — the `uq_user_ledger_source_entry`
  unique constraint, checked via `UserLedgerRepository::existsForSource()` before inserting. This is
  what makes re-running the backfill command, or re-processing a resent sync, a safe no-op.
- **`sourceSyncRecord` is nullable with `ON DELETE SET NULL`, not `CASCADE`** — a rollback purges
  superseded `SyncRecord` rows (`SyncRecordRepository::deleteByClubFromWeek()`), but a dividend draw
  already folded into a user's balance must survive that purge (same reasoning as
  `PlayerCareerStatSnapshot::$syncRecord`). No other rollback special-casing: a dividend draw
  recorded against a later-rolled-back timeline isn't retroactively reversed, same precedent as
  `Transfer`/`PlayerCareerStatSnapshot`.
- **`app:backfill-user-ledger`** pre-filters `sync_record` via a raw `payload::jsonb -> 'ledger' @>
  '[{"category":"dividend_draw"}]'::jsonb` containment query (payload is stored as `json`, not
  `jsonb`, so the cast is explicit) rather than hydrating every historical sync — then replays
  candidates in `server_timestamp ASC` order (real-world chronological, not per-club, since the
  running balance is per-*user* across every club they own), keeping an in-memory per-user running
  balance for the whole pass rather than re-querying per row.
- **`UserLedgerCrudController`** (admin) is read-only, same pattern as `NotificationLogCrudController`.
- **The admin User edit page (`/admin/user/{id}/edit`) surfaces this.** `UserCrudController::configureCrud()` points `crud/edit` at `admin/user_edit.html.twig`, which extends `@EasyAdmin/crud/edit.html.twig` and overrides only `content_header_wrapper` (`{{ parent() }}` first) — the real form is untouched. `edit()` adds `clubsSummary`/`clubKitConfigs`/`overallBalancePence` onto EasyAdmin's own `KeyValueStore` rather than calling `$this->render()` directly (unlike `detail()`'s fully custom `user_profile.html.twig`), same technique as `PlayerCrudController::index()`'s `playerSummary` panel. Per club: composited home/away kit + badge previews (`kit-compositor.js`'s `composeKitSvg()`, 'small' size), last-sync week/league-position/form (via the shared `resultBadge` macro, extracted from `club_profile.html.twig` into `admin/_macros.html.twig` for this reuse), `totalCareerEarnings` vs. `UserLedgerRepository::getTotalDividendsByClub()`; plus the user's overall `getCurrentBalance()` at the top.

### Kit & Badge Identity (NpcClub, backend-owned)
`NpcClub` carries a nullable `identity` json column: `{home: KitVariant, away: KitVariant, badgeShape, badgePattern, badgeCentre, initials, badgeFill, badgeTrim, badgeSymbol}`, where a `KitVariant` is `{kit, primary, secondary, shorts, socks}` — two full, independent kits (a club's home and away colors), plus one shared badge. Matches `assets/Components/KitBadge/kitSprite.ts`/`KitSprite.tsx` in `wunderkind-app` (that component takes one `KitConfig` at a time — render it once per variant, e.g. `<KitSprite config={identity.home} />` / `<KitSprite config={identity.away} />`). `kit`/`primary`/`secondary`/`shorts`/`socks` (both variants) reuse the **exact same enums** as the player appearance system (`KitStyle`, `KitColor`, `KitPart`) so a club's kit never drifts from a player's kit vocabulary; `BadgeShape`/`BadgePattern`/`BadgeCentre` are new, cohabiting in `src/Enum/Appearance/` for the same reason.
- **The home kit is the single source of truth for `primaryColor`/`secondaryColor`.** Those two existing (still `NOT NULL`) string columns are no longer independently admin-editable (`NpcClubCrudController` hides them on the form, `->hideOnForm()`, but still shows them read-only on the detail page) — `NpcClub::setIdentity()` syncs them from `identity['home']['primary']`/`['secondary']` every time a non-null identity with a home kit is set, so this happens automatically on both admin save and generation. `NpcClubGenerationService`'s old separate 20-color color-pair generator (`pickColorPair()`) is gone, but its **WCAG-contrast-checked-pair rule lives on**: `randomKitVariant()`'s `primary`/`secondary` (called once each for home and away, independently) are picked via `pickContrastingKitColor()` — up to 20 random tries for a candidate scoring `contrastRatio() >= 3.0` against `primary`, falling back to whichever palette color scores highest — same algorithm as the old `contrastRatio()`/`relativeLuminance()`, just re-scoped to the smaller 12-color `KitColor` palette (every color in that palette clears 3.0 against at least white or black, so the fallback path is a safety net, not something normal generation actually hits).
- **One creation path, no lifecycle subscriber.** Unlike Player/Staff/Scout/Agent's `AppearanceLifecycleSubscriber` (multiple creation paths), `NpcClub` is only ever constructed in `NpcClubGenerationService::generateClubs()`, so `generateIdentity()` (which calls `randomKitVariant()` twice, once per side) is just a private method called inline there — no Doctrine event needed.
- `badgeCentre` allows `none` and `initials` as real, admin-settable choices, but the **generator (and the widget's Randomise button) exclude both** — a blank badge centre isn't a useful thing to roll randomly, and a generated club's `initials` are a mechanical name-abbreviation, not a designed badge centre. Generated clubs only ever land on `star`/`ball`/`crown`. Randomise also rolls `badgeFill` as 50% chance equal to the **home** kit's primary (not away's).
- `initials` is sanitized server-side on submit (`KitIdentityType::mapFormsToData()`: upper-cased, `A-Z0-9` only, max 3 chars) regardless of what the widget's text input sent — mirrors the frontend's `sanitiseInitials()`.
- The admin widget (`KitIdentityType` + `templates/admin/field/kit_identity.html.twig` + `public/assets/kit-identity-widget.js`) follows the same architecture as the appearance widget (native fields `.visually-hidden`, not `display:none`; swatches read hex straight off each hex-backed enum's option values). Three tabs — **Kit Home / Kit Away / Badge** — and the live preview itself swaps between home-kit-only, away-kit-only, and badge-only content depending on the active tab, rather than always showing one combined preview. The form's own child fields are flat and prefixed (`homeKit`, `awayPrimary`, ...) rather than using Symfony's nested-form-group machinery — simpler given `KitIdentityType` already has a custom `DataMapperInterface`, which nests them back into `home`/`away` on submit. **Shorts/socks/trousers-style `KitPart` fields must never be rendered as a full kit thumbnail** — shorts/socks aren't visible in the shirt-only crop used for the kit-style grid, so every option renders as an identical shirt icon regardless of which part color is selected; render plain color chips instead (`.ap-chip`/`.ap-chip-swatch` in `admin-appearance-widget.css`), resolved against that variant's own live primary/secondary (or the fixed white/black) — this bit both the player appearance widget and this one before being fixed. `public/assets/kit-compositor.js` is a mechanical port of `kitSprite.ts`'s `buildKitGrid()`/`buildBadgeGrid()`/`toKitRuns()` — port it verbatim if the sprite's drawing logic ever changes, same rule as `avatar-compositor.js`.
- `LeagueImportExportService`'s club export/import (hand-maintained, guarded by a reflection-based coverage test in `LeagueImportExportRoundTripTest`) includes `identity` verbatim (nested shape and all — it's schema-agnostic passthrough, no changes needed there for the home/away restructure) — casts must check `!== null` before `(array)`, since casting a null JSON value directly would silently turn it into `[]` and break the round-trip.

### Personality Matrix (Player, Staff, Scout)
The 8-spoke matrix (`determination, professionalism, ambition, loyalty, adaptability, pressure, temperament, consistency`, each **1–20**) lives in the `PersonalityProfile` `#[ORM\Embeddable]`, mapped onto `player`, `staff` and `scout` as `personality_<trait>` SMALLINT columns defaulting to 10.

- **Personality is independent of ability.** It does **not** derive from `potential`, `coachingAbility` or `experience` — how good an entity is says nothing about what it is like. (An earlier model anchored traits on those stats; it produced flat, interchangeable profiles and was removed.)
- **Generation is mould-driven, not per-trait.** `PersonalityGeneratorService::rollTraits(PersonalityContext)` runs a fixed six-step pipeline: pick a `PersonalityMould` by weight → base-roll all eight traits Gaussian(μ, σ) → apply the mould (dominants 15–20, flaws 1–7, moderates 8–13; `BALANCED` clamps everything to 7–14) → apply correlation rules → apply role floors → clamp 1–20. The point is trade-offs: a standout strength paid for with a real flaw.
- **`PersonalityMould` is NOT `PlayerArchetype`.** The mould is an internal generation shape, never persisted and never serialized. `PlayerArchetype` is the shipped catalogue the **client** classifies against after the fact, and stays the single source of archetype truth. The two layers share vocabulary — don't let them share code.
- **Precedence is load-bearing, in this order:** mould pins a trait → correlations may only touch traits the mould left free (the design spec is explicit that an archetype overrides a correlation) → role floors are applied last and override everything, because a manager who folds under pressure would not be a manager.
- **Correlations read a frozen snapshot**, never the mutating matrix. Evaluating them in sequence against live values let the Mercenary Divergence cap Loyalty and thereby erase the Club Stalwart trigger before it was tested — whichever rule ran first silently suppressed the other. `applyCorrelations()` is public so this precedence can be asserted directly rather than inferred from sampled output.
- **The μ=10.5 / σ=3.2 / ~5–8%-extremes baseline describes the _base roll_, not the emitted population.** Steps 3–5 push traits to the ends deliberately, so finished matrices are far more extreme than the underlying Gaussian. Assert the baseline against `gaussianInt()`, which is also the codebase's only Box-Muller draw (`PlayerGenerationService::bellCurveInt` delegates to it).
- **`PersonalityContext` carries the band**: `forPlayer(age)` (≤16 σ=4.2 with Pressure/Consistency skewed to μ=8; 17–20 σ=3.2; 21+ σ=2.8), `forStaff(role)` (σ=2.8; `MANAGER`/`COACH` floor Determination ≥11, Temperament ≥9, Pressure ≥12), `forScout()` (σ=2.8; Adaptability ≥13, Consistency ≥12).
- **Note on age bands:** `PoolConfig` defaults `playerAgeMin/Max` to **12/13** — this is a youth-academy game and generated players get `Guardian` rows. The 17+ bands exist because `PoolConfig` is admin-editable, but under default config only the youth band ever fires.
- **Staff/Scout generation is centralised on persist.** `PersonalityLifecycleSubscriber` (Doctrine `prePersist`) fills any Staff/Scout whose profile is still all-defaults, covering every creation path (`MarketPoolService`, `app:generate-market-data`, admin) — same pattern as `AppearanceLifecycleSubscriber`. It only fires on **creation**, so pre-existing pool rows keep whatever they had; regenerate the pool to roll them. **Player is deliberately excluded**: `PlayerGenerationService` rolls the matrix inside its blueprint because the result feeds back into the power/stamina/heart derivation.
- **`isDefault()` is the "ungenerated" signal.** An embedded value object is never null, so all-traits-at-10 is the only marker available.
- **`PersonalityProfile::toArray()` is the single serialized shape** — used by `buildPlayerSnapshot`, `buildStaffSnapshot`, `buildScoutSnapshot`, and `MarketDataService::serialize{Coach,Scout}`. Don't re-inline the eight keys. `Agent` has no matrix.

### Player↔Agent Association (world pack)
`Player` has a nullable `?Agent $agent` FK (a **many-players-to-one-agent** relationship — one agent represents several players). Agents are a persistent shared pool (never deleted on consume, unlike Player/Staff). Agent surfacing:
- `buildPlayerSnapshot` nests the agent under each player via `Agent::toSnapshotArray()` (`{id, name, commissionRate, reputation, experience, rating, nationality, dateOfBirth}` or `null`; excludes the internal `judgements`) — the single shared agent shape, also used by `ScoutSearchController::serializePlayer`. Don't re-inline that array.
- At world-pack generation (`buildLeaguesPack`/`buildTierPack`), the loaded pool (`AgentRepository::findAll()`) is first bounded by `selectBoundedAgentPool()` to `ceil(estimatedNpcPlayers / StarterConfig::worldPackPlayersPerAgent)` (default 12 → ~12 players/agent, capped at pool size), then `assignAgents($players, $boundedAgents)` **reassigns every NPC-club player** a random agent from that bounded subset before the player is snapshotted and deleted. Without the bound, distinct agents surfaced would scale with the whole pool (one agent per player). Association follows the same structural nesting as player↔club/staff↔club (there is no player↔club FK; association is the snapshot nesting).
- **Dependency:** the ratio caps at pool size, so for a full country pack to reach its target the agent pool must hold ≥ that many agents (`PoolConfig::agentPoolTarget`, default 100). Agent generation is additive and agents are never consumed, so pools can balloon — the world-pack bound makes surfacing correct regardless.
- Agent pool size is `PoolConfig::agentPoolTarget` (default 100), driving generation/replenishment in `MarketPoolService`. `MarketPoolService::generatePlayers` also assigns pool players a random agent at generation time; the world-pack pass reassigns.

### Server-Driven Messaging
Operator-authored announcements (`AdminMessage`), separate from the in-game `InboxMessage`
fiction. Full client contract: `docs/api/server-driven-messaging.md`.

- **Targeting reads `Club`; delivery is keyed to `User`.** Cohort axes (reputation, league tier,
  country, week, tutorial state) only exist on `Club`, so eligibility is evaluated against the
  polling club — but `MessageDelivery` is keyed `(user, message)`. That split matters:
  `ClubRepository::findByUser()` returns only the *most recently created* club, so a club-keyed
  delivery row would let a player start a new club and have every active announcement replay.
  Acking therefore needs no club at all.
- **Guests and registered accounts are the same thing here.** Both are plain `User` rows;
  nothing in this system branches on `isVerified()` or the `@guest.buildmyclub.local` domain.
  (Registration still creates a *new* `User` when a guest signs up with a real email, so an
  upgrading guest may re-see a dismissed message — that is an account-linking gap in
  `SyncController::register()`, not something delivery keying can fix.)
- **Delivery-once** is enforced by a `NOT EXISTS` clause in
  `AdminMessageRepository::findCandidatesForClub()` against `MessageDelivery` rows in a terminal
  status. The `uq_message_delivery` unique constraint is **load-bearing**:
  `AdminMessageService::acknowledge()` is a PostgreSQL `INSERT … ON CONFLICT DO UPDATE` against
  it, which is what makes a repeated ack idempotent. Catching
  `UniqueConstraintViolationException` from a `flush()` would not work — Doctrine closes the
  EntityManager on a failed flush.
- **Two-phase targeting.** The SQL query resolves broadcast/direct fully, but only proves that
  *some* audience group qualified. `AdminMessageService::isEligible()` then re-checks each group
  on its own terms in PHP — manual membership included. Skipping that lets a non-member through
  on a message that also carries a dynamic group.
- **`leagueTier` criteria are inverted** — tier 1 is the top division, 8 is where new clubs
  start. `AudienceCriteriaEvaluator` fails **closed**: an unrecognised criteria key makes the
  group match nothing, so an admin typo under-delivers rather than broadcasting to everyone.
- **`bodyHtml` is sanitized on write** by `AdminMessageCrudController::persistEntity()`/
  `updateEntity()` via the `admin_message` named sanitizer
  (`config/packages/html_sanitizer.yaml`, autowired as `HtmlSanitizerInterface $adminMessage`).
  `style`/`class` are stripped so admin CSS cannot bleed into the client theme. The API emits
  the stored HTML verbatim.
- **Admin `json` column fields need generic `Field::new()`**, not `TextareaField` —
  the Text configurator rejects an array value with "can't be converted into a string". See
  `AudienceGroupCrudController::configureFields()`.

### Languages & Translations (backend-owned)

A `Language`/`TranslationKey`/`Translation` system covering two independent domains that
share one storage model but are served to the client in different ways — don't conflate
them:

- **Generic UI copy** (`TranslationKey.entityType === null`) — brand-new strings with no
  existing source of truth. Served as a flat `{_meta, entries}` catalogue,
  `GET /api/translations/{code}` (and `/version` for cheap polling), matching
  `wunderkind-app/locales/<code>/translations.json`'s own shape so the app's existing
  locale-file format and this backend's output are interchangeable. `_meta.pluralSensitiveKeys`/
  `bandedReferences` are structural metadata about which *keys* need special client-side
  handling — identical across every language, not translated values — so they live on
  `TranslationKey`, never duplicated per language.
- **Narrative content** (`GameEventTemplate`/`FacilityTemplate`/`Excursion`/`PlayerArchetype`
  text — `entityType` set) — **localized in place** on those entities' own existing
  endpoints (`/api/events/templates`, `/api/excursions`, `/api/game-config`,
  `/api/archetypes`) via a `?lang=` param, **not** exposed through the catalogue above. This
  was a deliberate choice over routing narrative text through synthetic catalogue keys: it
  means none of the client's existing rendering code for these screens needs new lookup
  logic, only to pass the current language through. Full client contract:
  `docs/api/translations.md`. `PlayerArchetype.name` is the one translatable field that also
  feeds a cache-busting version hash (`PlayerArchetypeRepository::findAllWithVersionHash()`,
  same pattern `ExcursionRepository::findActiveWithVersionHash()` uses) — it was previously
  missing from that hash entirely (only `description` was, after an earlier fix), which
  `?lang=` support would have made a real bug: two languages differing only in `name` would
  have hashed identically.
- **The default (EN) value for a narrative field is never duplicated into a stored
  `Translation` row — it is always read live off the owning entity**
  (`GameEventTemplate::getTitle()` etc.) via `NarrativeTranslationService::getFieldValue()`.
  A stored EN mirror would go stale the instant an admin edits the entity through its normal
  CRUD form — the same class of bug `NarrativeFacilityTemplateRoundTripTest`'s docblock
  documents for `FacilityTemplate`'s own export/import split (`baseConstructionWeeks`
  exported and never read back). Generic UI-copy keys have no live source, so their EN
  values *are* ordinary stored rows, like any other language.
- **Explicit typed link columns, not key-string parsing.** `TranslationKey.entityType`/
  `entitySlug`/`fieldName` are the real link to a narrative field; `key` itself (e.g.
  `narrative.excursion.team_bonding.title`) is a derived, human-readable label only, never
  parsed to find the association — same precedent as `player_1.morale` vs bare
  `player.morale` elsewhere in this file: explicit slots over string convention.
  `NarrativeTranslationService::TRANSLATABLE_FIELDS` is the hand-maintained registry of
  which fields on each entity type are translatable (most columns — `slug`, `category`,
  `cost` — are deliberately not).
- **Admin editing**: each of `GameEventTemplateCrudController`/`FacilityTemplateCrudController`/
  `ExcursionCrudController`/`PlayerArchetypeCrudController` gets a row-level "Translations"
  action (`NarrativeTranslationController`) — a grid of translatable fields × enabled
  languages, EN shown read-only (edit it on the entity's own form instead). Generic UI-copy keys get
  their own CRUD screens (`LanguageCrudController`, `TranslationKeyCrudController`,
  `TranslationCrudController`) plus a bulk import/export screen
  (`/admin/translations/content|export|import`, mirroring `ConfigImportExportService`'s own
  trio) whose export format *is* the public catalogue shape — the app's own locale file can
  be uploaded there close to verbatim as the initial `en` seed. That screen's optional
  "clear existing" checkbox is scoped to **one language's generic values only** — never a
  full wipe like the narrative import's own checkbox — because a bulk import always carries
  just one language's file; clearing every language would destroy unrelated languages' work
  as collateral damage. `TranslationRepository::deleteGenericForLanguage()` deletes that
  language's rows first, then `TranslationKeyRepository::findGenericOrphaned()` prunes any
  key left with zero translations in *any* language (a key still holding a value elsewhere
  is never touched).
  `NarrativeImportExportService`'s export/import additionally carries a `translations`
  section (non-default-language values only, for the four narrative types) so a whole
  language's worth of narrative translations can move with a narrative-content backup —
  bumped `EXPORT_VERSION` to `3` for this, then to `4` when `Excursion` joined the service's
  own base-content export/import below.
- **`Excursion`'s own base content (title, body, cost, etc. — not just its translations) is
  also now part of `NarrativeImportExportService`'s bulk export/import**, via
  `Excursion::toArray()` (same self-verifying convention `FacilityTemplate::toArray()`
  already used) + `exportExcursions()`/`upsertExcursion()`. Previously Excursion was the one
  "narrative content" entity with no bulk JSON path at all — only `app:seed-excursions`
  (hand-maintained PHP array, still independent and unaffected) and direct admin edits
  touched it. Joining this service means `clearAll()` now wipes `Excursion` rows and purges
  its `TranslationKey`s too — a deliberate reversal of that method's previous explicit
  exclusion of Excursion (its old reasoning — "clearAll() never touches Excursion rows, so
  purging would orphan live translations" — no longer applies once Excursion is fully
  round-trippable like the other three).
- **`GameEventTemplate`/`PlayerArchetype`/`TacticalAdvantage` still export via hand-written
  field lists** (not a `toArray()` method) inside `NarrativeImportExportService` — verified
  complete for every currently-persisted column, but with no reflective test guarding
  against the next field silently going the way `FacilityTemplate`'s
  `baseConstructionWeeks` once did. `NarrativeFieldCoverageTest` closes that gap for
  `GameEventTemplate`/`PlayerArchetype` (reflects each entity's ORM columns against one
  exported row); `TacticalAdvantage` is skipped deliberately — 3 trivial fields, and no
  unique business key a test fixture could clean up without risking a real catalogue row.
- **Don't cache a `TranslationKey`'s children via `$key->getTranslations()`.** That inverse
  `OneToMany` collection is only populated by Doctrine's hydrator for a `TranslationKey`
  loaded *from* the database — one created earlier in the same request (e.g. by
  `ensureKeyFor()` during a save) has its `Translation` children persisted via the owning
  side only, and the inverse collection is never retroactively updated. Query via
  `TranslationRepository::findAllForKey()` instead; this bit `NarrativeImportExportService`'s
  own `exportTranslations()` during manual testing — silently returning zero rows for data
  that definitely existed. Relatedly: don't give `NarrativeTranslationService::ensureKeyFor()`
  an instance-level result cache either — it goes stale the moment something else deletes the
  row it points to, then feeds Doctrine a detached-entity reference on the next call
  (`ORMInvalidArgumentException`, confusingly far from the real cause). The one caller that
  needs duplicate-protection (`saveTranslations()`, saving the same not-yet-existing field for
  two languages in one call) dedupes with a local, call-scoped map instead — see its own
  docblock.
- **`app:seed-default-language` only acts when the `language` table is completely empty** —
  it creates the single bootstrap `en`/enabled/default row and is a permanent no-op
  afterward. Deliberately *not* an upsert-by-code like `app:seed-excursions`: an
  upsert-on-every-deploy model would permanently stomp an admin's later choice of a
  different default language. Paired with `app:backfill-narrative-translations`
  (idempotent, ensures every existing event/facility/excursion/archetype row's
  `TranslationKey`s exist — cheap, both run on every deploy). Archetype translations
  survive `app:seed-archetypes`' own truncate-and-reseed-by-slug behavior unscathed: the
  link is by slug *string*, not a DB foreign key to the (truncated, re-autoincremented)
  `PlayerArchetype` row, so a reseed never orphans them.

### Two Firewalls
- **`api`** — stateless JWT, covers `/api/*`; role `ROLE_CLUB` for game clients
- **`admin`** — session form_login, covers `/admin`; role `ROLE_ADMIN`

Symfony `RouterListener` runs at priority 32, `FirewallListener` at priority 8 — the router runs first. `json_login`'s `check_path` **must** be a real registered route or the router returns 404. The stub route in `SyncController::login()` exists for this reason.

### EasyAdmin Custom Routes

Rule + example live in `src/Controller/Admin/CLAUDE.md` (auto-loaded when working in that
directory): custom admin routes must always redirect through EasyAdmin's entry point.

### Key Gotchas
- **PostgreSQL** — migrated from MySQL 8.0. New migrations must use Doctrine Schema API or PostgreSQL syntax (no `AUTO_INCREMENT`, no `ENGINE=InnoDB`).
- **`rank`** is a reserved SQL word — `LeaderboardEntry` uses column name `rank_position`.
- **`hallOfFamePoints`** is **server-derived, not client-supplied** — `Σ GameConfig::$leagueWinPoints[tier]` over `SeasonRecord` rows with `finalPosition = 1` (`HallOfFameScoreService`). `SyncRequest::$hallOfFamePoints` is accepted but ignored. Recomputed in `LeagueService::concludeSeason()` and by `app:leaderboards:generate`; mirrored onto `Club::$hallOfFamePoints` so `/api/club/status` matches the board. **`reputation`** floors at 0. **`totalCareerEarnings`** adds deltas.
- **Leaderboard scores** are absolute values from Club state at sync time, not running sums.
- **Doctrine JSON dirty-check** — when a `json` column stores mixed PHP string/int types, Doctrine silently skips the UPDATE. Bypass with:
  ```php
  $em->getConnection()->executeStatement('UPDATE ... SET col = :val WHERE id = :id', ['val' => json_encode($data), 'id' => $id]);
  ```
- **Player deduplication** — `StarterPackService` uses `spl_object_id()` (not `array_unique(SORT_REGULAR)`) to deduplicate pool results. Doctrine returns the same PHP object for the same DB row; `array_unique` with `==` comparison is unreliable on entity proxies.
- **EasyAdmin admin grant**:
  ```bash
  lando psql -c "UPDATE \"user\" SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'you@example.com';"
  ```
- **Adding a persisted field is not done until it round-trips through Import/Export.** Three admin screens back up and restore domain data, and a field the service doesn't carry is silently lost on restore — the import reports no error, the value just comes back at its entity default. `ConfigImportExportService` (`GameConfig`, `StarterConfig`, `PoolConfig`) is **reflection-driven**: it walks every `#[ORM\Column]` property, so a new config field is covered automatically and the only decision is whether it belongs on `ConfigImportExportService::DENIED_PROPERTIES` (secrets and runtime state — the export file is documented to admins as safe to commit). `NarrativeImportExportService` and `LeagueImportExportService` are still hand-maintained lists that must be edited on **both** sides. All three are guarded by coverage tests (`tests/Service/ConfigImportExportCoverageTest.php`, `NarrativeFacilityTemplateRoundTripTest.php`, `LeagueImportExportRoundTripTest.php`) that fail when an entity gains a column the service doesn't handle — so a red build here means the export needs updating, not the test. `NarrativeTranslationRoundTripTest.php` guards the newer `translations` section the same way, but narrower — it only asserts every field named in `NarrativeTranslationService::TRANSLATABLE_FIELDS` has a working getter/setter, not a full column sweep (most columns on those three entities are deliberately not translatable).
- **`GameEventTemplate` JSON is client-interpreted, and the client is fussy.** The backend stores and serves `impacts`, `firingConditions`, `chainedEvents` and `severity` verbatim — nothing validates them, so a wrong key is not an error, it is an event that quietly does nothing. Three traps: `impacts` has **two shapes**, and the `player_reputation`/`player_milestone`/`player_morale`/`player_form` categories read **only** `{"stat_changes": [...]}` (a flat `[{target, delta}]` array on those fires the message and applies nothing); a legacy target **must carry its slot number** (`player_1.morale`, never `player.morale` — the client's entity map has no bare `player` key); and setting `firingConditions` at all **removes the template from the weekly random roll**, so a shape no evaluator claims makes the event permanently dead. Only eight personality traits exist (`determination, professionalism, ambition, loyalty, adaptability, pressure, temperament, consistency`, 1–20) — any other name is silently dropped. Full reference: `docs/event-guide.md`, mirrored in the admin help on `GameEventTemplateCrudController`. `app:events:repair` fixes existing rows; the seeders skip existing slugs unless you pass `--update`.
- **EasyAdmin custom form type on a `json`/array column** — a `Field::new('col')->setFormType(MyType::class)` where `col` is a Doctrine `json` type gets auto-configured by EasyAdmin as a collection, which injects `CollectionType` options (`allow_add`, `entry_type`, …) onto your form type and throws `The options ... do not exist`. Tolerate them in the type's `configureOptions()`: `$resolver->setDefined(['allow_add','allow_delete','delete_empty','entry_options','entry_type'])`. To render a fully custom widget for such a compound type, register a form theme via `$crud->addFormTheme(...)` (singular) and define a `{% block <blockPrefix>_widget %}` block (block prefix = the type class minus `Type`, snake_cased; `AppearanceType` → `appearance`). See `AppearanceType` + `templates/admin/form/appearance_theme.html.twig`.

## API Endpoints

Full endpoint table (method, path, auth, description): `.context/stages/04_interfaces/output/routes.md`.
Admin UI is at `/admin` (session-based, `ROLE_ADMIN`).

## Key Services

| Service | Responsibility |
|---|---|
| `SyncService` | Sync processing, anti-cheat, leaderboard upsert, manager trait shifts |
| `EconomicService` | Financial year-end, sponsor contracts, player market value |
| `InboxService` | Generate and respond to inbox offers (sponsors, investors) |
| `MarketPoolService` | Generate and assign market entities; Player/Staff assign deletes entity and returns snapshot |
| `MarketDataService` | Serve market data to the client |
| `ClubInitializationService` | Create Club entity, set paName + manager traits, abbreviation |
| `StarterPackService` | Pull starting Player/Staff/Scout from pool; build snapshots; delete consumed Player/Staff |
| `PlayerGenerationService` | Procedurally generate a `Player` from position and source, including its `PersonalityProfile`. (Despite the name it does **not** read `PlayerArchetype` — archetypes are classified client-side.) |
| `AppearanceGeneratorService` | Deterministic sprite-config generation from `(id, role, age, nationality?)`; paired with `AppearanceLifecycleSubscriber` (prePersist auto-fill) — see Avatar Appearance above |
| `ArchetypeResolverService` | Admin-only preview of the dual (positive + negative) archetype a personality matrix resolves to — mirrors the documented client-side formula; see PlayerArchetype below |
| `PersonalityGeneratorService` | Rolls the 8-spoke Personality Matrix via the mould pipeline; shared by Player, Staff and Scout, which differ only in the `PersonalityContext` they pass. Paired with `PersonalityLifecycleSubscriber` (prePersist auto-fill for Staff/Scout) — see Personality Matrix above |
| `NpcClubGenerationService` | Generate NPC clubs with names, colors, facilities, ability by tier, and a kit+badge identity |
| `WorldInitializationService` | Build the full league pyramid + tier pack snapshot for a client; snapshot builders for Player/Staff/Scout |
| `LeagueService` | Assign clubs to leagues, conclude seasons, roll league sponsors |
| `FixtureGenerationService` | Generate match fixtures for a league season |
| `TransferLeaderboardService` | Rank players by transfer fee across clubs |
| `WorldPackCacheService` | Cache country/nationality worldpack data (`CountryWorldPackCache`) |
| `NameGeneratorService` | Procedural name generation for players and PA personas |
| `EmailVerificationService` | Send and validate email verification / password reset tokens |
| `ConfigImportExportService` | Export/import `GameConfig`, `StarterConfig`, and `PoolConfig` rows as JSON |
| `LeagueImportExportService` | Export/import `League` + `NpcClub` world data (used for admin-driven world pack management) |
| `NarrativeImportExportService` | Export/import event templates, facility templates, player archetypes, `TacticalAdvantage` rows, and (non-default-language only) narrative `translations` |
| `AdminMessageService` | Resolve pending announcements for a club, cap the payload, upsert acknowledgements, sanitize admin HTML |
| `AudienceCriteriaEvaluator` | Evaluate a DYNAMIC `AudienceGroup`'s JSON criteria against a `Club`, live at poll time |
| `UserLedgerService` | Detect `dividend_draw` entries in the sync ledger and centralize them onto `UserLedger`, chaining before/after balance across clubs — see Centralized User Ledger above |
| `NarrativeTranslationService` | Find-or-create `TranslationKey`s for narrative fields; builds the per-language localization map the `?lang=` endpoints read; EN is always read live off the entity, never stored — see Languages & Translations above |
| `TranslationCatalogueService` | Builds the generic UI-copy `{_meta, entries}` catalogue (`/api/translations/{code}`), shared by the public endpoint and the admin bulk-export |
| `TranslationCatalogueImportExportService` | Bulk import/export of the generic UI-copy catalogue, one language at a time — a separate domain from `NarrativeImportExportService`'s own `translations` section |

## Key Entities (non-obvious fields)

- **User** (game account, distinct from `Admin`) — `email`, `password`, `roles`, `clubs` (OneToMany); owner identity: `name`, `nationality`, `gender`, `dob`, `appearance` json — see Owner Identity above. `getUserIdentifier()` returns email; no uniqueness constraint on the `Club` FK (a user can own more than one club — `ClubRepository::findByUser()` resolves the most recent; `ClubRepository::findAllByUser()` returns every club they own).
- **UserLedger** — centralized, cross-club financial audit trail for one `User`; currently only `DIVIDEND_DRAW` entries (`UserLedgerEntryType`). `amountPence` (signed pence, corrected from the ledger's 100x-inflated raw value), `balanceBeforePence`/`balanceAfterPence` (running cross-club balance snapshot per row), `occurredAt` (in-game date) vs `recordedAt` (real-world date). `ManyToOne` → `user:User` (not nullable, `CASCADE`), `club:Club` (not nullable), `sourceSyncRecord:?SyncRecord` (nullable, `SET NULL`). See Centralized User Ledger above.
- **Club** — `reputation`, `totalCareerEarnings`, `hallOfFamePoints`, `lastSyncedWeek`, manager traits (`temperament`/`discipline`/`ambition` 0–100 clamped setters), `paName`, `financialYearStart`, `balance`, `country`, `abbreviation`
- **Player** — `position` (PlayerPosition), `status` (PlayerStatus), `recruitmentSource`, `currentAbility`, `potential` (hard-capped, `currentAbility ≤ potential`); embeds `PersonalityProfile` (8 traits 0–100); ManyToMany self-ref siblings; nullable `?Agent $agent` FK (many players → one agent; assigned in `MarketPoolService` and reassigned at world-pack generation; surfaced in every player snapshot — see Player↔Agent Association); `appearance` json (see Avatar Appearance). **No club FK** — pool entity, deleted on consume.
- **Staff** — `role` (StaffRole), `coachingAbility`; `appearance` json; embeds `PersonalityProfile` (8 traits 1–20; `MANAGER`/`COACH` carry role floors). **No club FK** — pool entity, deleted on consume.
- **Scout / Agent** — pool entities (`Scout` no club FK, not deleted on assign); both carry `appearance` json. `Scout` also embeds `PersonalityProfile` (floored on adaptability/consistency); `Agent` does **not**. Note `Scout`/`Agent` use a single `name` field, not `firstName`/`lastName`.
- **PlayerArchetype** — curated catalogue of 20 personality archetypes, `polarity` (`ArchetypePolarity`: positive/negative, 10 each) + unique `slug` + `traitWeights` (json). Classification is **client-side**: the backend is a definitions catalogue only — there is no archetype FK on `Player`, and the client resolves one positive and one negative per player. `traitWeights.formula` keys must be exactly the eight `PersonalityProfile` fields and weights are **signed** (positive = "High trait", negative = "Low trait", absolute values sum to 1.0); traits are stored 1–20 and the client normalises to 0–100 before comparing to `threshold`. Seeded via `app:seed-archetypes` (truncates first), which now runs on both deploys. `ArchetypeResolverService` is an **admin-only** read-only mirror of that client-side scoring — it powers the resolved-archetype panel on the Player/Staff edit pages and changes nothing about gameplay; if the formula ever changes client-side, change it there too. `name`/`description` are translatable (see Languages & Translations) — `GET /api/archetypes?lang=` localizes them in place, same convention as events/facilities/excursions.
- **League** — `country`, `tier` (1–8), `promotionSpots`, `tvDeal`, `prizeMoney`, `leaguePositionPot`, `sponsorCount`; has `LeagueSponsor` collection
- **NpcClub** — `country`, `tier`, `reputation`, `balance`, `stadiumName`, `primaryColor`/`secondaryColor` (synced from `identity.home`, not independently admin-editable — see below), `playingStyle`, `financialApproach`; grouped into leagues for the world pack. `identity` json (nullable, see Kit & Badge Identity below) — home kit + away kit + club badge
- **FacilityTemplate** — canonical slug shared with frontend; `category` (TRAINING/MEDICAL/SCOUTING), `baseCost`, `weeklyUpkeepBase`, `matchdayIncome`, `matchdayIncomeMultiplier`; seeded via admin
- **GameConfig** — singleton row; all global gameplay constants (XP rates, injury chances, wage multipliers, attendance formulas, etc.); every `#[ORM\Column]` is exported by `ConfigImportExportService` unless denied
- **StarterConfig** — singleton row; league player ability ranges + fan base growth curves; JSON dirty-check workaround applies here; covered by `ConfigImportExportService` reflection
- **LeaderboardEntry** — UNIQUE(club, category, period); `rank_position` column (not `rank`)
- **GameEventTemplate** — narrative event definitions; `impacts`/`firingConditions`/`chainedEvents` are client-interpreted JSON edited as raw JSON in the admin (see Key Gotchas and `docs/event-guide.md`); `severity` is only read on the `NPC_INTERACTION` path
- **InboxMessage** — `senderType` (MessageSenderType), `offerData` (json), `status` (MessageStatus)
- **Transfer** — fee + agentCommission in pence/cents; `getNetProceeds()` helper; `occurredAt` (client) + `syncedAt` (server); `player_id` is `ON DELETE SET NULL`
- **MatchResult** — per-club match record; `goalsFor`, `goalsAgainst`, `week`, `season`, `fixtureId` (unique), `opponentClubName`, `isHome`, `homeGoals`, `awayGoals`, `round`, `playedAt`, `yellowCards`; FK to `Club`
- **TacticalAdvantage** — matchup table row: `style` vs `opponentStyle` (both `PlayingStyle`) → `multiplier` (float); seeded via `NarrativeImportExportService`
- **Admin** — separate admin user entity (`UserInterface`); `email`, `password`, `name`, `department`, `accessLevel`; always `ROLE_ADMIN`; created via `app:admin:create`
- **BetaRequest** — beta-access waitlist entry; `email`, `code`, `valid`, `attempts`, `expiresAt`, `verifiedAt`; verified via `/api/beta-request/verify`
- **PoolConfig** — per-country/tier configuration for how many entities to pre-warm in the pool; covered by `ConfigImportExportService` reflection
- **AdminMessage / AudienceGroup / AudienceGroupMember / MessageDelivery** — server-driven messaging; `MessageDelivery` is keyed `(user, message)` while targeting reads `Club` (see Server-Driven Messaging)
- **SeasonRecord / SeasonSnapshot / SeasonRatingsSnapshot** — historical season data persisted at `conclude-season`
- **Language** — admin-configured language row; `code` (2-letter, treated immutable once shipped), `isEnabled`, `isDefault` (exactly one at a time, enforced in `LanguageCrudController`, not a DB constraint), `sortOrder`
- **TranslationKey** — a translatable string. `entityType`/`entitySlug`/`fieldName` (all null = generic UI-copy key; all set = linked to one narrative field — explicit columns, never parsed from `key`); `isPluralSensitive`/`bandedReferences` (structural, per-key, language-independent). `UNIQUE(key)` and `UNIQUE(entityType, entitySlug, fieldName)` — the latter relies on Postgres treating `NULL`s as distinct so multiple generic rows never collide. `OneToMany` → `translations` (cascade remove, `orphanRemoval: true`) — see Languages & Translations above for why that collection must never be read directly off an in-memory instance.
- **Translation** — one language's value for one `TranslationKey`. `UNIQUE(translationKey, language)`; an empty string is a deliberate value, "untranslated" is the absence of a row.
