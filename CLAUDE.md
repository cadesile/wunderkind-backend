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

# Seed data
lando php bin/console app:seed-game-events        # narrative event templates
lando php bin/console app:seed-archetypes         # player archetypes
lando php bin/console app:generate-market-data    # agents, scouts, investors, sponsors
lando php bin/console app:warm-pool               # pre-warm the market entity pool
lando php bin/console app:warm-world-pack         # warm country/nationality data cache

# Debug
lando php bin/console debug:router
lando php bin/console debug:firewall
```

**PHPUnit is installed** (`phpunit.dist.xml` present) but test coverage is sparse — test stubs exist in `tests/`.

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
1. **Legacy sync** — receives aggregate deltas, updates `Club` totals
2. **Anti-cheat** — rejects `weekNumber` rollbacks; every sync recorded in `SyncRecord` even if invalid
3. **Leaderboards** — upserts `LeaderboardEntry` rows for `all-time` and current ISO week

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
      → EconomicService: financial year-end + sponsor check + age-out
      → flush → return JSON
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
| `src/Security/` | Custom auth handlers (e.g. verification-aware success handler) |
| `migrations/` | Doctrine migrations |
| `config/jwt/` | RSA keypair (gitignored) |

## API Endpoints

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/api/register` | Public | Create user + club |
| `POST` | `/api/login` | Public | JWT login → token |
| `POST` | `/api/sync` | JWT | Anti-cheat sync + leaderboard upsert |
| `GET` | `/api/leaderboard/{category}` | JWT | Leaderboard by category + period |
| `GET` | `/api/market-data` | JWT | Agents, scouts, investors, sponsors |
| `GET` | `/api/game-config` | JWT | Global game configuration values |
| `GET` | `/api/events/templates` | JWT | Narrative event templates (cached 1hr) |
| `GET/POST` | `/api/inbox` / `/api/inbox/{id}/respond` | JWT | Inbox offers |
| `GET/POST` | `/api/finance` / `/api/finance/year-end` | JWT | Financial summary + year-end trigger |
| `GET` | `/api/pool` | JWT | Market entity pool |
| `POST` | `/api/market/assign` | JWT | Assign market entity to club |
| `GET` | `/api/squad` | JWT | Player/staff squad data |
| `GET` | `/api/archetypes` | JWT | Player archetypes |
| `POST` | `/api/club/initialize` | JWT | Initialize a new club |
| `GET` | `/api/starter-config` | JWT | League ability ranges |
| `GET` | `/api/league` | JWT | League data |

Admin UI is at `/admin` (session-based, `ROLE_ADMIN`).

## Key Services

| Service | Responsibility |
|---|---|
| `SyncService` | Sync processing, anti-cheat, leaderboard upsert, manager trait shifts |
| `EconomicService` | Financial year-end, sponsor contracts, age-out forced sales, player market value |
| `InboxService` | Generate and respond to inbox offers (sponsors, investors, agents) |
| `MarketPoolService` | Generate and assign market entities (agents, scouts, investors, sponsors); wage scaling by rep |
| `MarketDataService` | Serve market data to the client |
| `ClubInitializationService` | Initialize Club + starting squad/staff/market entities |
| `LeagueService` | League fixture generation, season progression |
| `WorldPackCacheService` | Country/nationality worldpack data cache |
| `NameGeneratorService` | Procedural name generation for players/PA |

## Key Entities (non-obvious fields)

- **Club** — `reputation`, `totalCareerEarnings`, `hallOfFamePoints`, `lastSyncedWeek`, manager traits (`temperament`/`discipline`/`ambition` 0–100 clamped), `paName`, `financialYearStart`
- **Player** — `position` (PlayerPosition enum), `status` (PlayerStatus enum), `recruitmentSource`, `currentAbility`, `potential`; embeds `PersonalityProfile` (8 traits 0–100: confidence/maturity/teamwork/leadership/ego/bravery/greed/loyalty); ManyToMany self-ref siblings; `ageOutWarningIssued`, `forcedSaleExecuted`
- **GameConfig** — singleton row; all global gameplay constants (XP rates, injury chances, wage multipliers, attendance formulas, etc.)
- **StarterConfig** — singleton row; league player ability ranges + fan base growth curves; JSON dirty-check workaround applies here
- **LeaderboardEntry** — UNIQUE(club, category, period); `rank_position` column (not `rank`)
- **InboxMessage** — `senderType` (MessageSenderType), `offerData` (json), `status` (MessageStatus)
- **Transfer** — fee + agentCommission in pence/cents; `getNetProceeds()` helper; `occurredAt` (client) + `syncedAt` (server)
