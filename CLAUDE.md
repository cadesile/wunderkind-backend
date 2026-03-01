# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Dev Environment

All PHP commands must run inside the Lando container:

```bash
lando start                          # spin up PHP 8.4 + MySQL 8.0
lando php bin/console <command>      # Symfony console
lando composer <command>             # Composer
lando mysql                          # MySQL shell (db: wunderkind, user/pass: wunderkind)
```

## Common Commands

```bash
# Cache
lando php bin/console cache:clear

# Database — initial setup on a fresh clone
lando php bin/console doctrine:database:drop --force
lando php bin/console doctrine:database:create
lando php bin/console doctrine:schema:create
lando php bin/console doctrine:migrations:sync-metadata-storage
lando php bin/console doctrine:migrations:version --add --all --no-interaction

# Generate JWT keys (only needed once or after key rotation)
lando php bin/console lexik:jwt:generate-keypair

# After adding/changing entities, generate a new migration
lando php bin/console doctrine:migrations:diff

# Debug
lando php bin/console debug:router
lando php bin/console debug:firewall
```

## Architecture

### Sync Model
The server is **not** the game engine. All gameplay (Weekly Tick, training, aging, personality changes) runs on-device. This API handles three things only:
1. **Legacy metrics** — receives aggregate deltas from the client and updates `Academy` totals
2. **Anti-cheat** — rejects `weekNumber` rollbacks; every sync is recorded in `SyncRecord` even if invalid
3. **Leaderboards** — upserts `LeaderboardEntry` rows for `all-time` and current ISO week on every valid sync

### Request Flow (POST /api/sync)
```
JWT firewall → SyncController::sync()
  → #[MapRequestPayload] deserializes + validates SyncRequest DTO
  → SyncService::process()
      → AcademyRepository::findByUser()
      → persist SyncRecord (always)
      → anti-cheat check → 409 if week < lastSyncedWeek
      → update Academy aggregates
      → LeaderboardEntryRepository::findOrCreate() × 6 (3 categories × 2 periods)
      → flush → return JSON
```

### Security / Firewall Order
Symfony's `RouterListener` runs at **priority 32**, the security `FirewallListener` at **priority 8** — the router runs first. This means `json_login`'s `check_path` **must** be a real registered route or the router returns 404 before the authenticator can intercept. The stub route in `SyncController::login()` exists for this reason.

### Key Gotchas
- **UUID columns** are `BINARY(16)` (Doctrine's `uuid` type for MySQL) — not `VARCHAR(36)`. The migration reflects this.
- **`rank`** is a reserved word in MySQL 8.0. `LeaderboardEntry` uses the column name `rank_position`.
- **`hallOfFamePoints`** is `max(current, incoming)` — it never decreases. **`reputation`** floors at 0. **`totalCareerEarnings`** adds the delta.
- **Leaderboard scores** are absolute values derived from Academy state at sync time, not running sums of deltas.

### Source Layout
| Path | Purpose |
|---|---|
| `src/Entity/` | Doctrine ORM entities |
| `src/Enum/` | PHP 8.1 backed enums (PlayerPosition, PlayerStatus, RecruitmentSource, StaffRole, TransferType, LeaderboardCategory) |
| `src/Dto/` | Validated input DTOs (deserialized via `#[MapRequestPayload]`) |
| `src/Repository/` | Doctrine repositories with domain-specific query methods |
| `src/Service/` | Business logic (SyncService) |
| `src/Controller/` | Thin HTTP layer — delegates to services |
| `src/ApiResource/` | API Platform v4 resource definitions (future use) |
| `migrations/` | Doctrine migrations |
| `config/jwt/` | RSA keypair for JWT (gitignored, generated via `lexik:jwt:generate-keypair`) |
