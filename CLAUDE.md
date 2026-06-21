# CLAUDE.md

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
lando php bin/console app:seed-archetypes         # player archetypes
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

Test coverage is sparse — 38 pre-existing errors in the suite are from stale test stubs unrelated to current code.

## Git Workflow

Always use feature branches — never commit directly to `master`.

```bash
git checkout -b feat/<short-description>
git push -u origin HEAD
gh pr create --title "..." --body "..."
```

Branch naming: `feat/`, `fix/`, `chore/` prefixes. Base branch is `master`.

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

### Key Gotchas
- **PostgreSQL** — migrated from MySQL 8.0. New migrations must use Doctrine Schema API or PostgreSQL syntax (no `AUTO_INCREMENT`, no `ENGINE=InnoDB`).
- **`rank`** is a reserved SQL word — `LeaderboardEntry` uses column name `rank_position`.
- **`hallOfFamePoints`** is `max(current, incoming)` — never decreases. **`reputation`** floors at 0. **`totalCareerEarnings`** adds deltas.
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
| `GET` | `/api/leaderboard/{category}` | JWT | Leaderboard by category + period |
| `GET` | `/api/market/data` | JWT | Market pool (agents, scouts, investors, sponsors) |
| `POST` | `/api/market/assign` | JWT | Assign market entity to club; Player/Staff returns `snapshot` key |
| `POST` | `/api/market/consume` | JWT | Consume/use a market entity |
| `GET` | `/api/game-config` | JWT | Global game configuration values |
| `GET` | `/api/events/templates` | JWT | Narrative event templates (cached 1hr) |
| `GET` | `/api/inbox` / `GET /api/inbox/{id}` | JWT | Inbox offers |
| `POST` | `/api/inbox/{id}/accept` | JWT | Accept inbox offer |
| `POST` | `/api/inbox/{id}/reject` | JWT | Reject inbox offer |
| `GET` | `/api/finance/overview` | JWT | Financial summary |
| `GET` | `/api/finance/investors` | JWT | Investor contracts |
| `GET` | `/api/finance/sponsors` | JWT | Sponsor contracts |
| `POST` | `/api/finance/sponsors/{id}/terminate` | JWT | Early-terminate a sponsor contract |
| `GET` | `/api/pool/ensure` | JWT | Ensure market pool is warm for club |
| `GET` | `/api/archetypes` | JWT | Player archetypes |
| `POST` | `/api/club/initialize` | JWT | Initialize a new club + world data |
| `GET` | `/api/club/status` | JWT | Club initialization status |
| `GET` | `/api/club/foreign` | JWT | Foreign clubs for scouting |
| `GET` | `/api/starter-config` | JWT | League ability ranges |
| `GET` | `/api/league` | JWT | Club's current league data |
| `POST` | `/api/league/conclude-season` | JWT | Submit season results |
| `GET` | `/api/league/season-history` | JWT | Historical season records |
| `GET` | `/api/scout/search` | JWT | Search for players via scouts |
| `GET` | `/api/leaderboard/transfers/top-sellers` | JWT | Transfer leaderboard |
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
| `PlayerGenerationService` | Procedurally generate a `Player` from archetype, position, and source |
| `NpcClubGenerationService` | Generate NPC clubs with names, colors, facilities, and ability by tier |
| `WorldInitializationService` | Build the full league pyramid + tier pack snapshot for a client; snapshot builders for Player/Staff/Scout |
| `LeagueService` | Assign clubs to leagues, conclude seasons, roll league sponsors |
| `FixtureGenerationService` | Generate match fixtures for a league season |
| `TransferLeaderboardService` | Rank players by transfer fee across clubs |
| `WorldPackCacheService` | Cache country/nationality worldpack data (`CountryWorldPackCache`) |
| `NameGeneratorService` | Procedural name generation for players and PA personas |
| `EmailVerificationService` | Send and validate email verification / password reset tokens |

## Key Entities (non-obvious fields)

- **Club** — `reputation`, `totalCareerEarnings`, `hallOfFamePoints`, `lastSyncedWeek`, manager traits (`temperament`/`discipline`/`ambition` 0–100 clamped setters), `paName`, `financialYearStart`, `balance`, `country`, `abbreviation`
- **Player** — `position` (PlayerPosition), `status` (PlayerStatus), `recruitmentSource`, `currentAbility`, `potential` (hard-capped, `currentAbility ≤ potential`); embeds `PersonalityProfile` (8 traits 0–100); ManyToMany self-ref siblings. **No club FK** — pool entity, deleted on consume.
- **Staff** — `role` (StaffRole), `coachingAbility`. **No club FK** — pool entity, deleted on consume.
- **PlayerArchetype** — defines trait mapping distributions used by `PlayerGenerationService`; `traitMapping` (json); seeded via `app:seed-archetypes`
- **League** — `country`, `tier` (1–8), `promotionSpots`, `tvDeal`, `prizeMoney`, `leaguePositionPot`, `sponsorCount`; has `LeagueSponsor` collection
- **NpcClub** — `country`, `tier`, `reputation`, `balance`, `stadiumName`, `primaryColor`/`secondaryColor`, `playingStyle`, `financialApproach`; grouped into leagues for the world pack
- **FacilityTemplate** — canonical slug shared with frontend; `category` (TRAINING/MEDICAL/SCOUTING), `baseCost`, `weeklyUpkeepBase`, `matchdayIncome`, `matchdayIncomeMultiplier`; seeded via admin
- **GameConfig** — singleton row; all global gameplay constants (XP rates, injury chances, wage multipliers, attendance formulas, etc.)
- **StarterConfig** — singleton row; league player ability ranges + fan base growth curves; JSON dirty-check workaround applies here
- **LeaderboardEntry** — UNIQUE(club, category, period); `rank_position` column (not `rank`)
- **InboxMessage** — `senderType` (MessageSenderType), `offerData` (json), `status` (MessageStatus)
- **Transfer** — fee + agentCommission in pence/cents; `getNetProceeds()` helper; `occurredAt` (client) + `syncedAt` (server); `player_id` is `ON DELETE SET NULL`
- **PoolConfig** — per-country/tier configuration for how many entities to pre-warm in the pool
- **SeasonRecord / SeasonSnapshot / SeasonRatingsSnapshot** — historical season data persisted at `conclude-season`
