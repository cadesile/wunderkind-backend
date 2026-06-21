# Remove Player/Staff Club FK — Design

**Date:** 2026-06-21

## Problem

`Player` and `Staff` entities carry a `club_id` FK and `assigned_at` timestamp that model "which club owns this entity." This is architecturally wrong: the backend is a **data pool**. The frontend (client-authoritative) holds the real game state, including which players and staff belong to which club. The FK relationship introduced Doctrine cascade issues (`Club.players` / `Club.staff` with `cascade: ['persist', 'remove']`) and caused `ORMInvalidArgumentException` errors in production.

## Goal

Remove all club-ownership tracking from `Player` and `Staff`. Pool entities exist in the DB, get consumed (deleted) when the frontend picks them, and disappear. No stale assignments, no cascade problems, no club FK on pool entities.

## Architecture

**Pool lifecycle: generate → serve → delete.**

When a Player or Staff entity is returned to the frontend (via starter pack or market assign), it is immediately deleted from the DB. The frontend receives the full snapshot and stores it locally. The backend never needs to know which players/staff a club has.

Sponsor and Investor entities are **not** affected — they retain their club FK because they represent live financial contracts, not pool data.

Scout entities are **not** affected — they already have no club FK.

## Data Model Changes

### `Player` entity
- Remove `club` (`ManyToOne` → Club) field, `getClub()`, `setClub()`
- Remove `assignedAt` field, `getAssignedAt()`, `setAssignedAt()`, `isAssigned()`
- Remove `isInMarketPool()` (always true if entity exists)
- Remove `idx_player_club` and `idx_player_assigned_at` indexes

### `Staff` entity
- Same removals as Player: `club`, `assignedAt`, `isAssigned()`, `isInMarketPool()`
- Remove `idx_staff_club` and `idx_staff_assigned_at` indexes

### `Club` entity
- Remove `players` (`OneToMany` Player collection) + `getPlayers()`
- Remove `staff` (`OneToMany` Staff collection) + `getStaff()`

### Migration
```sql
-- Delete previously-assigned pool entities (frontend already has them)
DELETE FROM player WHERE club_id IS NOT NULL;
DELETE FROM staff  WHERE club_id IS NOT NULL;

-- Drop FK columns and assigned_at
ALTER TABLE player DROP COLUMN club_id;
ALTER TABLE player DROP COLUMN assigned_at;
ALTER TABLE staff  DROP COLUMN club_id;
ALTER TABLE staff  DROP COLUMN assigned_at;
```
Indexes on those columns are dropped at the same time.

## Repository Changes

### `PlayerRepository`
- `findInPool()` — remove `club IS NULL` filter
- `countInPool()` — `COUNT(*)` with no filter
- `countInPoolByNationality()` — remove `club IS NULL` filter
- `findForWorldInitByPosition()`, `findForWorldInitByPositionAndNationality()`, `findForeignForWorldInitByPosition()`, `findForWorldInit()` — remove `p.club IS NULL` / `club IS NULL` filters

### `StaffRepository`
- `findInPool()`, `findInPoolByRoleRandom()`, `countInPool()`, `countInPoolByNationalityAndRole()` — remove `club IS NULL` filters

### `WarmPoolCommand`
- `countForeignPlayers()`, `countForeignStaff()`, `countForeignScouts()` — remove `p.club IS NULL` / `s.club IS NULL` conditions

## Service Changes

### `StarterPackService`
- Remove `prewarmPoolForClub()` and `MarketPoolService` injection (the cascade problem no longer exists)
- Remove `$p->setClub($club)` and `$s->setClub($club)` lines
- After building snapshots, delete consumed Player and Staff entities: `$this->em->remove($p)` / `$this->em->remove($s)` then flush
- `$club->setStarterInitializedAt()` remains (it's on Club, not Player)

### `MarketPoolService.assignToClub()`
- Player branch: delete entity, return snapshot via `WorldInitializationService::buildPlayerSnapshot()`
- Staff branch: delete entity, return snapshot via `WorldInitializationService::buildStaffSnapshot()`
- Scout, Sponsor, Investor branches: unchanged

### `EconomicService`
- Remove `checkAgeOutPlayers()` method entirely
- Remove `ageOutWarningIssued` / `forcedSaleExecuted` / `forcedSaleWeek` field references if they only serve age-out logic

### `SyncService`
- Remove `$player->getClub() !== $club` ownership guard
- Remove `$player->setClub()` / `$player->setClub(null)` calls
- Keep Transfer recording — player ID is sufficient, no club FK needed on Player
- Remove call to `EconomicService::checkAgeOutPlayers()`

## Controller / Endpoint Changes

### Removed endpoints
| Method | Path | Reason |
|---|---|---|
| `GET` | `/api/squad` | Frontend holds squad data locally |
| `POST` | `/api/squad/release/{id}` | No server-side release needed |
| `GET` | `/api/staff` | Frontend holds staff data locally |

`SquadController` and `StaffController` are deleted entirely if these are their only routes.

### `MarketController` (`POST /api/market/assign`)
- Player/Staff: service deletes entity and returns snapshot; controller returns snapshot JSON
- Scout/Sponsor/Investor: no change

### `ClubController` (`GET /api/club/status`)
- Remove `playerCount` and `staffCount` from response (or omit fields)

### Admin `DashboardController`
- Remove `assignedPlayers` / `assignedStaff` stats
- All `WHERE club_id IS NULL` pool counts → `COUNT(*)` with no filter
- `DELETE FROM player WHERE club_id IS NULL` reset → `DELETE FROM player` (all pool entities)
- Same for staff

### `CleanupAssignedEntitiesCommand` (`app:cleanup:assigned-entities`)
- Remove Player and Staff cleanup branches (consumed immediately on assign)
- Keep Sponsor/Investor cleanup (still use `assignedAt`)

## Out of Scope
- Sponsor/Investor club FK (financial contracts, not pool data)
- Scout entity (no club FK already)
- Transfer entity (records historical data, untouched)
- Any frontend changes (user will handle separately)
