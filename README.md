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
| Database | MySQL 8.0 |
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

- [Lando](https://lando.dev/) (runs PHP 8.4 + MySQL 8.0 via Docker)

### Setup

```bash
# Start the environment
lando start

# Install dependencies
lando composer install

# Generate JWT keys (once, or after key rotation)
lando php bin/console lexik:jwt:generate-keypair

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
lando logs -s appserver           # view app logs
lando php bin/console debug:router    # inspect registered routes
lando php bin/console debug:firewall  # inspect firewall config
lando mysql                           # MySQL shell (db: wunderkind)
```

### Environment Variables

```bash
APP_ENV=
APP_SECRET=
DATABASE_URL=mysql://wunderkind:wunderkind@database:3306/wunderkind?serverVersion=8.0&charset=utf8mb4
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
| `POST` | `/api/sync` | `ROLE_ACADEMY` | Anti-cheat sync + leaderboard upsert |
| `GET` | `/api/leaderboard/{category}` | JWT | Leaderboard by category + period |
| `GET` | `/api/market-data` | JWT | Returns agents, scouts, investors, sponsors |

Admin UI is available at `/admin` (session-based, `ROLE_ADMIN`).

---

## Source Layout

| Path | Purpose |
|---|---|
| `src/Entity/` | Doctrine ORM entities |
| `src/Enum/` | PHP 8.1 backed enums |
| `src/Dto/` | Validated input/output DTOs |
| `src/Repository/` | Domain-specific query methods |
| `src/Service/` | Business logic |
| `src/Controller/` | Thin HTTP layer |
| `src/Controller/Admin/` | EasyAdmin CRUD controllers |
| `src/ApiResource/` | API Platform v4 resource definitions |
| `migrations/` | Doctrine migrations |
| `config/jwt/` | RSA keypair (gitignored) |

---

## Entities

| Entity | Key Fields |
|---|---|
| `User` | email, roles (`ROLE_ACADEMY` / `ROLE_ADMIN`), OneToOne Academy |
| `Academy` | name, reputation, totalCareerEarnings, hallOfFamePoints, lastSyncedWeek |
| `Player` | position, status, recruitmentSource, potential, currentAbility, PersonalityProfile |
| `PersonalityProfile` | confidence, maturity, teamwork, leadership, ego, bravery, greed, loyalty (0–100) |
| `Guardian` | demandLevel (1–10), loyaltyToAcademy (0–100), OneToOne Player |
| `Agent` | isUniversal, commissionRate, experience, rating, OneToMany Players |
| `Scout` | name, dob, nationality, judgements (json), experience |
| `Investor` | company, nationality, size (CompanySize), isActive |
| `Sponsor` | company, nationality, size (CompanySize), isActive |
| `Staff` | role, coachingAbility, scoutingRange, weeklySalary |
| `Transfer` | fee + agentCommission (pence); getNetProceeds() helper |
| `SyncRecord` | clientWeekNumber, isValid, invalidReason — every sync logged |
| `LeaderboardEntry` | UNIQUE(academy, category, period); score BIGINT; rank_position column |

**Enums:** `PlayerPosition`, `PlayerStatus`, `RecruitmentSource`, `StaffRole`, `TransferType`, `LeaderboardCategory`, `CompanySize`

---

## Security

Two separate firewalls:

- **`api`** — stateless JWT, covers `/api/*`
- **`admin`** — session form_login, covers `/admin`

Role separation: `ROLE_ACADEMY` for game clients, `ROLE_ADMIN` for the admin panel. See `config/packages/security.yaml` for full access control rules.

**Grant admin access:**
```bash
lando mysql -e "UPDATE user SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'you@example.com';" wunderkind
```

---

## Key Gotchas

- **UUID columns** are `BINARY(16)` (Doctrine's `uuid` type for MySQL) — not `VARCHAR(36)`.
- **`rank`** is reserved in MySQL 8.0; `LeaderboardEntry` uses column name `rank_position`.
- **`hallOfFamePoints`** is `max(current, incoming)` — never decreases.
- **`reputation`** floors at 0. **`totalCareerEarnings`** accumulates deltas.
- Symfony `RouterListener` (priority 32) runs before `FirewallListener` (priority 8) — `json_login` check_path must be a real registered route.

---

## Repositories

- `wunderkind-backend` — this repo: Symfony API & leaderboard engine
- `wunderkind-app` — React Native mobile application

---

Built with passion for the Business of Football.
