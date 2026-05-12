# Initialize Endpoint Redesign

**Date:** 2026-05-12
**Status:** Approved

## Problem

`POST /api/initialize` is a monolithic endpoint that does too much in a single HTTP request:

- Builds full NPC squads across all league tiers (8 leagues × 20 clubs × 20 players)
- Generates fixtures for every league
- Assembles the AMP starter pack
- Returns the entire worldpack as one JSON blob

This causes server-side timeouts (5-minute `set_time_limit` workaround), brittle retry behaviour, and oversized response payloads. The endpoint cannot be safely retried — a mid-flight failure has no resume path.

## Solution

Split initialization into four endpoints the client calls sequentially. Each step is independently retryable. A shared country-level cache (`CountryWorldPackCache`) ensures NPC tier data is generated at most once per country and served instantly on retry or for subsequent clubs in the same country.

---

## Endpoints

| Step | Endpoint | Method | Side Effects |
|------|----------|--------|--------------|
| 1 | `/api/initialize/starter` | POST | Assigns AMP players/staff/scouts to club, sets `starterInitializedAt` |
| 2 | `/api/initialize/leagues` | GET | None — pure read of league metadata for club's country |
| 3 | `/api/initialize/league/{tier}` | POST | Generates NPC squads for one tier, stores in `CountryWorldPackCache`, consumes pool players |
| 4 | *(implicit)* | — | After all tiers cached, sets `worldInitializedAt` on the club |

**Client flow:**
```
POST /starter → GET /leagues → POST /league/1 → POST /league/2 → … → POST /league/N
```

Each step can be retried independently. The loading overlay advances as each call completes.

---

## State Tracking

### Club entity changes
- Add `starterInitializedAt: DateTimeImmutable|null` — set when starter pack is successfully assembled and flushed.
- `worldInitializedAt` (existing) — set after all tiers for the club's country are cached.

### CountryWorldPackCache entity (new)
```
country_world_pack_cache
├── id            UUID PK
├── country       CHAR(2)          e.g. 'EN', 'ES'
├── tier          SMALLINT
├── payload       JSONB            clubs + players + fixtures for this tier
└── generated_at  TIMESTAMPTZ
UNIQUE (country, tier)
```

One row per country+tier combination. Written once (either by the pre-warm command or the first club to trigger that tier). All subsequent clients in the same country read from this cache — no pool players are consumed again.

No per-club cache table is needed. The starter pack is implicitly "cached" via the player/staff assignments persisted on the club entity itself.

---

## Guards & Error Responses

### POST /api/initialize/starter
| Condition | Status |
|-----------|--------|
| Club not found | 404 |
| Country not set (no `?country=` override resolves it) | 422 |
| Player pool too small (< 500) | 412 |
| `starterInitializedAt` already set | 409 + returns starter data (idempotent) |

### GET /api/initialize/leagues
| Condition | Status |
|-----------|--------|
| Club not found | 404 |
| `starterInitializedAt` not set | 412 |

### POST /api/initialize/league/{tier}
| Condition | Status |
|-----------|--------|
| Club not found | 404 |
| `starterInitializedAt` not set | 412 |
| Tier not found for club's country | 404 |
| `CountryWorldPackCache` row exists for (country, tier) | 200 — return cached payload immediately |
| `worldInitializedAt` already set | 409 |
| After storing tier: all tiers cached → set `worldInitializedAt` | — |

---

## Service Restructuring

### `StarterPackService` (new)
Extracted from `WorldInitializationService::initialize()`. Responsibilities:
- Distribute AMP starter players by position using PoolConfig weights
- Fill staff roles (manager, coach, director of football, facility manager, chairman)
- Assign scouts
- Assign players/staff to club (`$player->setClub($club)`)
- Set `starterInitializedAt`, flush
- Return starter payload array

### `WorldInitializationService` (trimmed)
- Retains: `buildLeaguesPack()`, `buildTierPack()` (new — single-tier variant), `buildLeagueSnapshot()`, `buildClubSnapshot()`, `buildPlayerSnapshot()`, `buildStaffSnapshot()`, `buildScoutSnapshot()`, `distributeByPosition()`
- Removes: `initialize()` (replaced by `StarterPackService` + controller orchestration)
- New method: `buildTierPack(Club $club, string $country, int $tier): array` — generates NPC squads for a single tier and returns the payload array. Consumes pool players.

### `WorldPackCacheService` (new)
Wraps `CountryWorldPackCache` reads/writes.
- `getOrBuild(string $country, int $tier, callable $generator): array` — checks cache, calls generator if miss, stores result, returns payload.
- `allTiersCached(string $country, array $tierIds): bool` — checks if all tier rows exist for a country.

### `WarmWorldPackCommand` (new)
`app:worldpack:warm {country} [--force]`
- Validates country code
- Fetches all leagues for the country
- With `--force`: drops existing `CountryWorldPackCache` rows for the country before iterating
- Iterates tiers, calls `WorldPackCacheService::getOrBuild()` for each
- Skips already-cached tiers (idempotent without `--force`)
- Outputs a progress line per tier: `[tier 1] generated (42 clubs, 840 players)` / `[tier 2] already cached — skipped`

### `InitializeController` (thinned)
```
POST /starter    → StarterPackService::initialize($club)
GET  /leagues    → LeagueRepository::findByCountry($country) → minimal metadata response
POST /league/{tier} → WorldPackCacheService::getOrBuild(..., fn() => WorldInitializationService::buildTierPack(...))
                   → if allTiersCached → club->setWorldInitializedAt() + flush
```

---

## Migration

One new migration:
- `ALTER TABLE club ADD COLUMN starter_initialized_at TIMESTAMPTZ NULL`
- `CREATE TABLE country_world_pack_cache (id UUID PK, country CHAR(2), tier SMALLINT, payload JSONB, generated_at TIMESTAMPTZ, UNIQUE(country, tier))`

---

## Pre-Warm Command Usage

```bash
# Pre-warm England before any English clubs initialize
lando php bin/console app:worldpack:warm EN

# Re-warm after a pool refresh (command is idempotent — skips cached tiers)
lando php bin/console app:worldpack:warm EN --force   # optional force-rebuild flag
```

The `--force` flag drops existing cache rows for the country before regenerating, allowing a full refresh after the pool is replenished.

---

## Out of Scope

- Streaming/chunked HTTP responses
- Symfony Messenger / async job queue
- Per-player cache invalidation
- Payload compression (can be added at the nginx layer independently)
