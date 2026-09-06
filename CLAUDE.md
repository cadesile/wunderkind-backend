# CLAUDE.md


<!-- context-generator: start -->
## Project Context

This project has a structured `.context/` folder for AI agent context (ICM format).
**Read `.context/CONTEXT.md` first** — it is the stage router that tells you which output
files are relevant to your task. Do not load the entire folder; use the router to scope what you read.

Regenerate with: `node generate_project_context.js`
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

# Seed data (run in this order on a fresh DB)
lando php bin/console app:seed-game-events        # narrative event templates
lando php bin/console app:seed-player-events      # player event templates
lando php bin/console app:seed:morale-events      # morale event templates
lando php bin/console app:seed-archetypes         # 20 curated archetypes (10 positive, 10 negative)
lando php bin/console app:seed-social-post-templates  # social media post templates
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

Always use feature branches — never commit directly to `master`.

```bash
git checkout -b feat/<short-description>
git push -u origin HEAD
gh pr create --title "..." --body "..."
```

Branch naming: `feat/`, `fix/`, `chore/` prefixes. Base branch is `master`.

**Two long-lived branches, each auto-deploying:** `master` → production, `dev` → the dev
environment. A push to either triggers a real deploy — see Deployment below. Feature
branches normally target `dev` first.

## Deployment

Full runbook: `docs/deploy/hetzner.md`. Context digest:
`.context/stages/01_overview/output/deployment.md`.

| Push to | Deploys to | Workflow | Image tag |
|---|---|---|---|
| `master` | `buildmyclub.co.uk`, `www.`, `api.` | `deploy-prod.yml` | `:prod` |
| `dev` | `dev.buildmyclub.co.uk`, `api.dev.` | `deploy-dev.yml` | `:dev` |

One Hetzner box. A **host-level Caddy container owns ports 80/443** and reverse-proxies by
hostname to the app containers over an external docker network called `web`. The app
containers serve **plain HTTP and bind no host ports**; `docker/nginx.conf` is a single
hostname-agnostic vhost, so one image backs every environment. Caddy manages its own
certificates — there is no certbot.

The proxy (`deploy/proxy/`) is shared infrastructure deployed **by hand**, never by a
per-branch workflow. The per-environment `.env` on the box is regenerated from scratch on
every deploy, so hand edits there are lost.

### Deployment gotchas

- **`TRUSTED_PROXIES=private_ranges` is required in every proxied environment.** Without it
  Symfony reads the docker bridge address as the client, ignores `X-Forwarded-Proto`, and
  emits `http://` URLs — breaking the admin `form_login` redirect and absolute links in
  emails.
- **Never add `ngx_http_realip_module` config to `docker/nginx.conf`.** Rewriting
  `$remote_addr` to the original client puts a *public* IP in `REMOTE_ADDR`, which fails
  the `private_ranges` check and reintroduces exactly that bug. nginx real_ip and Symfony
  `trusted_proxies` do the same job and conflict — Symfony owns it.
- **Keep the apex `buildmyclub.co.uk` in the Caddyfile.** `www` is a CNAME to it and it has
  its own A record, so apex traffic reaches the box; Caddy matches hostnames strictly.
- **`JWT_SECRET_KEY`/`JWT_PUBLIC_KEY` hold the RAW PEM, not base64.** Lexik is configured as
  `secret_key: '%env(JWT_SECRET_KEY)%'`, so the env var *is* the key material — it never reads
  `config/jwt/*.pem`. Set it with `gh secret set <NAME> < private.pem`. A base64 blob fails at
  sign time with `DECODER routines::unsupported`, *after* login has already succeeded, so the
  logs show a successful authentication followed by a 500. `jwt-entrypoint.sh` does `base64 -d`
  into `config/jwt/`, but those files are vestigial — nothing reads them and they are root-owned
  600, unreadable by the `www-data` workers. Don't infer the format from that script.
- **`CORS_ALLOW_ORIGIN` is a regex**, not a literal (`origin_regex: true`).
- **Do not add `app:backfill-appearances` or `cache:clear` to a deploy.** Both OOM-killed a
  prod deploy; the workflows carry inline comments explaining why.
- The baked crontab (`pool-warm`, `worldpack-warm`, `leaderboards-generate`) runs in
  **every** environment, dev included.
- There is **no staging tier** — the old `staging` stack was repurposed as dev.

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
`Player`, `Staff`, `Scout`, `Agent` each carry a nullable `appearance` json column holding the frontend `Appearance` shape (10 keys: `skinTone, hairStyle, hairColor, accessory, kitTrim, facialHair, faceShape, eyeShape, noseType, jerseyVariant`). The backend owns generation:
- `AppearanceGeneratorService` is a deterministic PHP port of the frontend `generateAppearance(id, role, age, nationality?)` — same `(id, role, age, nationality)` always yields the same avatar. The `App\Enum\Appearance\*` enums are the single source of truth shared by the generator and the admin dropdowns.
- **Skin tone is region-weighted.** `App\Enum\Appearance\WorldRegion` maps each `NameGeneratorService` demonym to a region and carries that region's percentage weights over `SkinTone::cases()` (weights sum to 100; `WorldRegionTest` enforces coverage of every generated nationality). The generator picks via `SeededRng::weightedPick()`, which consumes **exactly one** RNG draw — same as the uniform `pick()` it replaced — so supplying a nationality changes `skinTone` and leaves the other nine fields byte-identical. A null or unrecognised nationality falls back to the uniform pick. The weights table order is positional against `AppearanceGeneratorService::SKIN_TONES`; reordering either that const or the `SkinTone` enum silently remaps every region (a test pins them in lockstep).
- `AppearanceLifecycleSubscriber` (Doctrine `prePersist`) auto-fills `appearance` for any of the four entity types persisted without one, passing `getNationality()` through, so **every** creation path is covered centrally (don't hook individual construction sites). `app:backfill-appearances` fills pre-existing rows; `app:backfill-appearances --regenerate-skin-tone` additionally rewrites **only** the `skinTone` key on rows that already have an appearance, so avatars generated before the region table keep their hair, accessory and kit.
- `appearance` is emitted verbatim (a passthrough of `getAppearance()`) in every player/staff/scout/agent serializer — `buildPlayerSnapshot`/`buildStaffSnapshot`/`buildScoutSnapshot`, `MarketDataService::serialize{Coach,Scout,Agent}`, and `ScoutSearchController::serializePlayer`. The emitted object is a drop-in for the frontend `Appearance`; a null (un-backfilled) value is safe — the frontend falls back to its own generator.

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

### Two Firewalls
- **`api`** — stateless JWT, covers `/api/*`; role `ROLE_CLUB` for game clients
- **`admin`** — session form_login, covers `/admin`; role `ROLE_ADMIN`

Symfony `RouterListener` runs at priority 32, `FirewallListener` at priority 8 — the router runs first. `json_login`'s `check_path` **must** be a real registered route or the router returns 404. The stub route in `SyncController::login()` exists for this reason.

### EasyAdmin Custom Routes
Custom admin POST-action routes must **always** redirect through EasyAdmin's entry point:

```php
// CORRECT — initialises the `ea` context
return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_my_route']));

// WRONG — bypasses `ea` context; causes "i18n on null" Twig error
return $this->redirectToRoute('admin_my_route');
```

This rule generalizes beyond redirects: `AdminRouterSubscriber` only populates the `ea` Twig context when the **matched route** carries EasyAdmin's `routeCreatedByEasyAdmin` flag — true only for the dashboard's own `/admin` route, regardless of HTTP method. So **any** custom action that renders an `@EasyAdmin`-extending template directly (not just ones that redirect) must also be *reached* via `/admin?routeName=...`, not hit as a plain route — including POST actions. In Twig, target such a form at `path('admin', {routeName: 'admin_my_route'})` rather than `path('admin_my_route')`; `routeName` is read from the query string, so this works for POST bodies too.

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
- **Adding a persisted field is not done until it round-trips through Import/Export.** Three admin screens back up and restore domain data, and a field the service doesn't carry is silently lost on restore — the import reports no error, the value just comes back at its entity default. `ConfigImportExportService` (`GameConfig`, `StarterConfig`, `PoolConfig`) is **reflection-driven**: it walks every `#[ORM\Column]` property, so a new config field is covered automatically and the only decision is whether it belongs on `ConfigImportExportService::DENIED_PROPERTIES` (secrets and runtime state — the export file is documented to admins as safe to commit). `NarrativeImportExportService` and `LeagueImportExportService` are still hand-maintained lists that must be edited on **both** sides. All three are guarded by coverage tests (`tests/Service/ConfigImportExportCoverageTest.php`, `NarrativeFacilityTemplateRoundTripTest.php`, `LeagueImportExportRoundTripTest.php`) that fail when an entity gains a column the service doesn't handle — so a red build here means the export needs updating, not the test.
- **EasyAdmin custom form type on a `json`/array column** — a `Field::new('col')->setFormType(MyType::class)` where `col` is a Doctrine `json` type gets auto-configured by EasyAdmin as a collection, which injects `CollectionType` options (`allow_add`, `entry_type`, …) onto your form type and throws `The options ... do not exist`. Tolerate them in the type's `configureOptions()`: `$resolver->setDefined(['allow_add','allow_delete','delete_empty','entry_options','entry_type'])`. To render a fully custom widget for such a compound type, register a form theme via `$crud->addFormTheme(...)` (singular) and define a `{% block <blockPrefix>_widget %}` block (block prefix = the type class minus `Type`, snake_cased; `AppearanceType` → `appearance`). See `AppearanceType` + `templates/admin/form/appearance_theme.html.twig`.

## Source Layout

| Path | Purpose |
|---|---|
| `src/Entity/` | Doctrine ORM entities |
| `src/Enum/` | PHP 8.1 backed enums |
| `src/Dto/` | Validated input/output DTOs (`#[MapRequestPayload]`) |
| `src/Repository/` | Domain-specific query methods |
| `src/Service/` | Business logic |
| `src/Command/` | Symfony console commands (seeding, market generation, cleanup) |
| `src/Controller/Api/` | Thin HTTP layer — game client endpoints |
| `src/Controller/Admin/` | EasyAdmin CRUD + custom admin routes |
| `src/Form/Type/` | Custom Symfony form types (used in admin JSON field editors) |
| `src/Security/` | Custom auth handlers (e.g. verification-aware success handler) |
| `src/Doctrine/Function/` | Custom DQL functions (e.g. `RAND()`) |
| `migrations/` | Doctrine migrations |
| `config/jwt/` | RSA keypair (gitignored) |

## API Endpoints

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/api/register` | Public | Create user + club |
| `POST` | `/api/login` | Public | JWT login → token |
| `POST` | `/api/verify-email` | Public | Verify email address |
| `POST` | `/api/resend-verification` | Public | Resend verification email |
| `POST` | `/api/forgot-password` | Public | Trigger password reset flow |
| `POST` | `/api/reset-password` | Public | Complete password reset |
| `POST` | `/api/beta-request` | Public | Submit beta access request |
| `POST` | `/api/sync` | JWT | Anti-cheat sync + leaderboard upsert |
| `POST` | `/api/account/delete` | JWT | Permanently delete the caller's account + all owned clubs and their data |
| `GET` | `/api/leaderboard/{category}` | JWT | Leaderboard by category + period |
| `GET` | `/api/app-links` | Public | App store / deep link URLs |
| `GET` | `/api/market/data` | JWT | Market pool (agents, scouts, investors, sponsors) |
| `GET` | `/api/market/legacy` | JWT | Legacy market data format |
| `POST` | `/api/market/assign` | JWT | Assign market entity to club; Player/Staff returns `snapshot` key |
| `POST` | `/api/market/consume` | JWT | Consume/use a market entity |
| `GET` | `/api/game-config` | JWT | Global game configuration values |
| `GET` | `/api/events/templates` | JWT | Narrative event templates (cached 1hr) |
| `GET` | `/api/inbox` / `GET /api/inbox/{id}` | JWT | Inbox offers |
| `POST` | `/api/inbox/{id}/accept` | JWT | Accept inbox offer |
| `POST` | `/api/inbox/{id}/reject` | JWT | Reject inbox offer |
| `POST` | `/api/inbox/{id}/read` | JWT | Mark inbox message as read |
| `GET` | `/api/finance/overview` | JWT | Financial summary |
| `GET` | `/api/finance/investors` | JWT | Investor contracts |
| `GET` | `/api/finance/sponsors` | JWT | Sponsor contracts |
| `POST` | `/api/finance/sponsors/{id}/terminate` | JWT | Early-terminate a sponsor contract |
| `POST` | `/api/pool/ensure` | JWT | Ensure market pool is warm for club |
| `GET` | `/api/archetypes` | Public | Curated archetype catalogue (10 positive + 10 negative); ETag/`versionHash` cached |
| `GET` | `/api/messages/pending` | JWT | Undelivered admin announcements for the club (capped: 1 blocking + 5 other) |
| `POST` | `/api/messages/{id}/ack` | JWT | Record a message as `displayed`/`dismissed`; idempotent upsert |
| `POST` | `/api/club/initialize` | JWT | Initialize a new club + world data |
| `GET` | `/api/club/status` | JWT | Club initialization status |
| `GET` | `/api/club/check` | JWT | Check if club exists for current user |
| `GET` | `/api/club/foreign` | JWT | Foreign clubs for scouting |
| `GET` | `/api/club/name-options` | JWT | Generated club name options |
| `GET` | `/api/starter-config` | JWT | League ability ranges |
| `GET` | `/api/league` | JWT | Club's current league data |
| `POST` | `/api/league/conclude-season` | JWT | Submit season results |
| `GET` | `/api/league/season-history` | JWT | Historical season records |
| `GET` | `/api/league/season-history/{season}` | JWT | Season record detail |
| `GET` | `/api/scout/search` | JWT | Search for players via scouts |
| `GET` | `/api/scout/foreign-clubs` | JWT | NPC clubs available for scout searches |
| `GET` | `/api/leaderboard/transfers/top-sellers` | JWT | Top transfer seller leaderboard |
| `GET` | `/api/leaderboard/transfers/most-valuable` | JWT | Most valuable players leaderboard |
| `GET` | `/api/admin/stats` | JWT + ROLE_ADMIN | Backend stats |

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
| `AppearanceGeneratorService` | Deterministic avatar generation (port of frontend `generateAppearance`); paired with `AppearanceLifecycleSubscriber` (prePersist auto-fill) — see Avatar Appearance above |
| `ArchetypeResolverService` | Admin-only preview of the dual (positive + negative) archetype a personality matrix resolves to — mirrors the documented client-side formula; see PlayerArchetype below |
| `PersonalityGeneratorService` | Rolls the 8-spoke Personality Matrix via the mould pipeline; shared by Player, Staff and Scout, which differ only in the `PersonalityContext` they pass. Paired with `PersonalityLifecycleSubscriber` (prePersist auto-fill for Staff/Scout) — see Personality Matrix above |
| `NpcClubGenerationService` | Generate NPC clubs with names, colors, facilities, and ability by tier |
| `WorldInitializationService` | Build the full league pyramid + tier pack snapshot for a client; snapshot builders for Player/Staff/Scout |
| `LeagueService` | Assign clubs to leagues, conclude seasons, roll league sponsors |
| `FixtureGenerationService` | Generate match fixtures for a league season |
| `TransferLeaderboardService` | Rank players by transfer fee across clubs |
| `WorldPackCacheService` | Cache country/nationality worldpack data (`CountryWorldPackCache`) |
| `NameGeneratorService` | Procedural name generation for players and PA personas |
| `EmailVerificationService` | Send and validate email verification / password reset tokens |
| `ConfigImportExportService` | Export/import `GameConfig`, `StarterConfig`, and `PoolConfig` rows as JSON |
| `LeagueImportExportService` | Export/import `League` + `NpcClub` world data (used for admin-driven world pack management) |
| `NarrativeImportExportService` | Export/import event templates, facility templates, player archetypes, and `TacticalAdvantage` rows |
| `AdminMessageService` | Resolve pending announcements for a club, cap the payload, upsert acknowledgements, sanitize admin HTML |
| `AudienceCriteriaEvaluator` | Evaluate a DYNAMIC `AudienceGroup`'s JSON criteria against a `Club`, live at poll time |

## Key Entities (non-obvious fields)

- **Club** — `reputation`, `totalCareerEarnings`, `hallOfFamePoints`, `lastSyncedWeek`, manager traits (`temperament`/`discipline`/`ambition` 0–100 clamped setters), `paName`, `financialYearStart`, `balance`, `country`, `abbreviation`
- **Player** — `position` (PlayerPosition), `status` (PlayerStatus), `recruitmentSource`, `currentAbility`, `potential` (hard-capped, `currentAbility ≤ potential`); embeds `PersonalityProfile` (8 traits 0–100); ManyToMany self-ref siblings; nullable `?Agent $agent` FK (many players → one agent; assigned in `MarketPoolService` and reassigned at world-pack generation; surfaced in every player snapshot — see Player↔Agent Association); `appearance` json (see Avatar Appearance). **No club FK** — pool entity, deleted on consume.
- **Staff** — `role` (StaffRole), `coachingAbility`; `appearance` json; embeds `PersonalityProfile` (8 traits 1–20; `MANAGER`/`COACH` carry role floors). **No club FK** — pool entity, deleted on consume.
- **Scout / Agent** — pool entities (`Scout` no club FK, not deleted on assign); both carry `appearance` json. `Scout` also embeds `PersonalityProfile` (floored on adaptability/consistency); `Agent` does **not**. Note `Scout`/`Agent` use a single `name` field, not `firstName`/`lastName`.
- **PlayerArchetype** — curated catalogue of 20 personality archetypes, `polarity` (`ArchetypePolarity`: positive/negative, 10 each) + unique `slug` + `traitWeights` (json). Classification is **client-side**: the backend is a definitions catalogue only — there is no archetype FK on `Player`, and the client resolves one positive and one negative per player. `traitWeights.formula` keys must be exactly the eight `PersonalityProfile` fields and weights are **signed** (positive = "High trait", negative = "Low trait", absolute values sum to 1.0); traits are stored 1–20 and the client normalises to 0–100 before comparing to `threshold`. Seeded via `app:seed-archetypes` (truncates first), which now runs on both deploys. `ArchetypeResolverService` is an **admin-only** read-only mirror of that client-side scoring — it powers the resolved-archetype panel on the Player/Staff edit pages and changes nothing about gameplay; if the formula ever changes client-side, change it there too.
- **League** — `country`, `tier` (1–8), `promotionSpots`, `tvDeal`, `prizeMoney`, `leaguePositionPot`, `sponsorCount`; has `LeagueSponsor` collection
- **NpcClub** — `country`, `tier`, `reputation`, `balance`, `stadiumName`, `primaryColor`/`secondaryColor`, `playingStyle`, `financialApproach`; grouped into leagues for the world pack
- **FacilityTemplate** — canonical slug shared with frontend; `category` (TRAINING/MEDICAL/SCOUTING), `baseCost`, `weeklyUpkeepBase`, `matchdayIncome`, `matchdayIncomeMultiplier`; seeded via admin
- **GameConfig** — singleton row; all global gameplay constants (XP rates, injury chances, wage multipliers, attendance formulas, etc.); every `#[ORM\Column]` is exported by `ConfigImportExportService` unless denied
- **StarterConfig** — singleton row; league player ability ranges + fan base growth curves; JSON dirty-check workaround applies here; covered by `ConfigImportExportService` reflection
- **LeaderboardEntry** — UNIQUE(club, category, period); `rank_position` column (not `rank`)
- **InboxMessage** — `senderType` (MessageSenderType), `offerData` (json), `status` (MessageStatus)
- **Transfer** — fee + agentCommission in pence/cents; `getNetProceeds()` helper; `occurredAt` (client) + `syncedAt` (server); `player_id` is `ON DELETE SET NULL`
- **MatchResult** — per-club match record; `goalsFor`, `goalsAgainst`, `week`, `season`, `fixtureId` (unique), `opponentClubName`, `isHome`, `homeGoals`, `awayGoals`, `round`, `playedAt`, `yellowCards`; FK to `Club`
- **TacticalAdvantage** — matchup table row: `style` vs `opponentStyle` (both `PlayingStyle`) → `multiplier` (float); seeded via `NarrativeImportExportService`
- **Admin** — separate admin user entity (`UserInterface`); `email`, `password`, `name`, `department`, `accessLevel`; always `ROLE_ADMIN`; created via `app:admin:create`
- **BetaRequest** — beta-access waitlist entry; `email`, `code`, `valid`, `attempts`, `expiresAt`, `verifiedAt`; verified via `/api/beta-request/verify`
- **PoolConfig** — per-country/tier configuration for how many entities to pre-warm in the pool; covered by `ConfigImportExportService` reflection
- **AdminMessage / AudienceGroup / AudienceGroupMember / MessageDelivery** — server-driven messaging; `MessageDelivery` is keyed `(user, message)` while targeting reads `Club` (see Server-Driven Messaging)
- **SeasonRecord / SeasonSnapshot / SeasonRatingsSnapshot** — historical season data persisted at `conclude-season`
