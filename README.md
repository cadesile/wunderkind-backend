# The Wunderkind Factory — Backend

The Wunderkind Factory is a mobile-first strategy game focused on the high-stakes business of youth football academy management. Players take on the role of an Academy Director, tasked with discovering, developing, and trading the world's next superstars in a charming, 16-bit retro-inspired world.

---

## Project Overview

Unlike traditional management sims, Wunderkind Factory prioritizes the "human element" of development. Success isn't just about high stats; it's about navigating complex personalities, managing demanding guardians, and negotiating with calculated agents.

**Core Pillars**

- **The Weekly Tick** — Time advances in discrete weekly intervals, processing training, injuries, and behavioral incidents.
- **Dynamic Personality Matrix** — An 8-spoke radar chart defines every player, influenced by management decisions (Praise/Punishment).
- **Data Abstraction** — No "under-the-hood" numbers. Performance and potential are judged via visual cues like stars, bars, and charts.
- **Hybrid Sync Engine** — Play offline anywhere; sync your academy's legacy and earnings to global leaderboards when connected.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | React Native (Mobile) |
| Backend | Symfony 8.0 (PHP 8.4) + API Platform v4 |
| Database | PostgreSQL 16 |
| Dev Ops | Lando + Docker |
| Auth | lexik/jwt-authentication-bundle v3.2 |
| Admin UI | EasyAdmin v5 |
| Persistence | MMKV (Client) / Doctrine ORM 3 (Server) |

---

## Architecture: The Hybrid Model

The game uses a **Client-Authoritative, Asynchronous Sync Model**:

- **Local Execution** — The "Weekly Tick" and core gameplay (Training, Morale, Aging) happen entirely on the device.
- **Legacy Sync** — High-level metrics (Total Career Earnings, Academy Reputation, Hall of Fame Points) are pushed to the Symfony API.
- **Anti-Cheat** — The API validates `weekNumber` to prevent rollback exploits; every sync is recorded in `SyncRecord` even if rejected.
- **Leaderboards** — Upserts `LeaderboardEntry` rows for `all-time` and current ISO week on every valid sync.

### Request Flow — `POST /api/sync`

```
JWT firewall → SyncController::sync()
  → #[MapRequestPayload] deserializes + validates SyncRequest DTO
  → SyncService::process()
      → AcademyRepository::findByUser()
      → persist SyncRecord (always)
      → anti-cheat check → 409 if week < lastSyncedWeek
      → update Academy aggregates
      → LeaderboardEntryRepository::findOrCreate() × 6
      → flush → return JSON
```

---

## Local Development

### Prerequisites

- [Lando](https://lando.dev/) (runs PHP 8.4 + PostgreSQL 16 via Docker)

### Setup

```bash
# Start the environment
lando start

# Install dependencies
lando composer install

# Generate JWT keys (once, or after key rotation)
lando php bin/console lexik:jwt:generate-keypair --no-pass --overwrite

# Fresh database setup
lando php bin/console doctrine:database:drop --force
lando php bin/console doctrine:database:create
lando php bin/console doctrine:schema:create
lando php bin/console doctrine:migrations:sync-metadata-storage
lando php bin/console doctrine:migrations:version --add --all --no-interaction

# Clear cache
lando php bin/console cache:clear
```

### Useful Commands

```bash
lando logs -s appserver                   # view app logs
lando php bin/console debug:router        # inspect registered routes
lando php bin/console debug:firewall      # inspect firewall config
lando psql                                # PostgreSQL shell (db: wunderkind)
```

### Environment Variables

```bash
APP_ENV=
APP_SECRET=
DATABASE_URL=postgresql://wunderkind:wunderkind@postgres:5432/wunderkind?serverVersion=16&charset=utf8
CORS_ALLOW_ORIGIN=
JWT_SECRET_KEY=
JWT_PUBLIC_KEY=
JWT_PASSPHRASE=
```

---

## API Endpoints

| Method | Path | Auth | Description |
|---|---|---|---|
| `POST` | `/api/register` | Public | Create user + academy |
| `POST` | `/api/login` | Public | JWT login → returns token |
| `POST` | `/api/sync` | JWT | Anti-cheat sync + leaderboard upsert |
| `GET` | `/api/leaderboard/{category}` | JWT | Leaderboard by category + period |
| `GET` | `/api/market-data` | JWT | Agents, scouts, investors, sponsors |
| `GET` | `/api/game-config` | JWT | Global game configuration values |
| `GET` | `/api/events/templates` | JWT | Narrative event templates |
| `GET` | `/api/inbox` | JWT | Inbox messages |
| `POST` | `/api/inbox/{id}/respond` | JWT | Accept or reject an inbox offer |
| `GET` | `/api/finance` | JWT | Financial year summary |
| `POST` | `/api/finance/year-end` | JWT | Trigger financial year-end processing |

Admin UI is available at `/admin` (session-based, `ROLE_ADMIN`).

---

## Admin Panel

The EasyAdmin v5 panel at `/admin` provides:

- **CRUD** — Read-only views for all core entities; writable for Scouts, Investors, Sponsors
- **Game Config** — Tune all global gameplay constants (clique thresholds, XP, injuries, scouting, wages, attendance, stadium capacity, etc.)
- **Starter Config** — Seed league player ability ranges and fan base growth curves
- **Pool Config** — Configure the market data generation pipeline
- **Import / Export** — JSON round-trip for narrative data (event templates, facility templates, player archetypes, tactical advantages)
- **App Links** — Manage deep-links shown in the mobile app
- **Settings & Tools** — Developer utilities (age-out trigger, entity cleanup, environment info)
- **Logs** — Tail `var/log/prod.log` from the browser

**Grant admin access:**
```bash
lando psql -c "UPDATE \"user\" SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'you@example.com';"
```

---

## Source Layout

| Path | Purpose |
|---|---|
| `src/Entity/` | Doctrine ORM entities |
| `src/Enum/` | PHP 8.1 backed enums |
| `src/Dto/` | Validated input/output DTOs |
| `src/Repository/` | Domain-specific query methods |
| `src/Service/` | Business logic |
| `src/Controller/Api/` | Thin HTTP layer — game client endpoints |
| `src/Controller/Admin/` | EasyAdmin CRUD + custom admin routes |
| `src/ApiResource/` | API Platform v4 resource definitions |
| `migrations/` | Doctrine migrations |
| `config/jwt/` | RSA keypair (gitignored) |

---

## Entities

| Entity | Key Fields |
|---|---|
| `User` | email, roles (`ROLE_CLUB` / `ROLE_ADMIN`), OneToOne Academy |
| `Academy` | name, reputation, totalCareerEarnings, hallOfFamePoints, lastSyncedWeek, manager traits |
| `Player` | position, status, recruitmentSource, potential, currentAbility, PersonalityProfile |
| `PersonalityProfile` | confidence, maturity, teamwork, leadership, ego, bravery, greed, loyalty (0–100) |
| `Guardian` | demandLevel (1–10), loyaltyToAcademy (0–100), OneToOne Player |
| `Agent` | isUniversal, commissionRate, experience, rating, OneToMany Players |
| `Scout` | name, dob, nationality, judgements (json), experience |
| `Investor` | company, tier (InvestorTier), investmentAmount, percentageOwned |
| `Sponsor` | company, monthlyPayment, contractStart/EndDate, status (SponsorStatus) |
| `Staff` | role (StaffRole), coachingAbility, scoutingRange, weeklySalary |
| `Transfer` | fee + agentCommission (pence); getNetProceeds() helper |
| `SyncRecord` | clientWeekNumber, isValid, invalidReason — every sync logged |
| `LeaderboardEntry` | UNIQUE(academy, category, period); score BIGINT; rank_position |
| `GameEventTemplate` | slug, category (EventCategory), weight, title, bodyTemplate, impacts (json) |
| `InboxMessage` | senderType, subject, body, offerData (json), status (MessageStatus) |
| `GameConfig` | All global gameplay constants (singleton row) |
| `StarterConfig` | League ability ranges + fan base growth curves (singleton row) |
| `FacilityTemplate` | slug, label, baseCost, upkeep, gameplayEffects (json), maxLevel |

**Enums:** `PlayerPosition`, `PlayerStatus`, `RecruitmentSource`, `StaffRole`, `TransferType`, `LeaderboardCategory`, `CompanySize`, `InvestorTier`, `SponsorStatus`, `MessageSenderType`, `MessageStatus`, `EventCategory`, `PlayingStyle`

---

## Security

Two separate firewalls:

- **`api`** — stateless JWT, covers `/api/*`
- **`admin`** — session form_login, covers `/admin`

Role separation: `ROLE_CLUB` for game clients, `ROLE_ADMIN` for the admin panel. See `config/packages/security.yaml` for full access control rules.

---

## Key Gotchas

- **PostgreSQL** — migrated from MySQL 8.0. All new migrations must use Doctrine Schema API or PostgreSQL syntax (no `AUTO_INCREMENT`, no `ENGINE=InnoDB`).
- **UUID columns** — Doctrine uses its `uuid` type, stored as UUID strings in PostgreSQL.
- **`rank`** is a reserved word in some SQL dialects; `LeaderboardEntry` uses column name `rank_position`.
- **`hallOfFamePoints`** is derived server-side from tier-weighted league titles (`HallOfFameScoreService`); the client-sent value is ignored.
- **`reputation`** floors at 0. **`totalCareerEarnings`** accumulates deltas.
- **Doctrine JSON dirty-check** — When a `json` column contains mixed PHP string/int types, Doctrine silently skips the UPDATE. Use `$em->getConnection()->executeStatement()` with `json_encode()` to bypass.
- Symfony `RouterListener` (priority 32) runs before `FirewallListener` (priority 8) — `json_login` check_path must be a real registered route.
- EasyAdmin custom POST actions must redirect through `$this->redirect($this->generateUrl('admin', ['routeName' => '...']))`, never `$this->redirectToRoute()` directly.

---

## Repositories

- `wunderkind-backend` — this repo: Symfony API & leaderboard engine
- `wunderkind-app` — React Native mobile application

---

Built with passion for the Business of Football.
