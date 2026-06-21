# Club Init Pool Prewarm — Design

**Date:** 2026-06-21

## Problem

`StarterPackService::initialize()` pulls the AMP club's starter players, staff, and scouts from the shared global pool, filtering by `(nationality, ability_range, position)`. If the pool is thin on the right nationality — due to high sign-up volume from the same country, or an under-warmed pool — the query falls back to foreign players. New users may not get same-nationality starters.

## Goal

Guarantee that when `StarterPackService` queries the pool during initialization, there are always enough same-nationality entities available to satisfy the starter config counts.

## Approach

Before running any pool queries, generate a targeted buffer of pool entities for the club's nationality and inject them directly into the shared pool. The existing pool queries then run unchanged and find their matches.

- **Players:** generate `starterPlayerCount * 2`. The 2× buffer absorbs ability-range variance — `PlayerGenerationService` doesn't accept ability bounds, so over-generating ensures enough fall within the `(ability_min, ability_max)` window used by `findForWorldInitByPositionAndNationality`. Excess entities remain in the pool for other purposes.
- **Staff:** generate the exact count for each role (manager, coach, chairman, DOF, facility manager — skipping any with a count of 0). `fillStaffRole` queries by role + nationality with no ability filter, so exact counts are reliable.
- **Scouts:** generate the exact count. `findInPool` doesn't filter by ability.

## Implementation

### 1. `StarterPackService` — inject `MarketPoolService`

Add `MarketPoolService` to the constructor.

### 2. New private method `prewarmPoolForClub()`

```php
private function prewarmPoolForClub(Club $club, StarterConfig $config, string $nationality): void
```

Called at the very top of `initialize()`, before any pool queries. Calls:

- `$this->marketPoolService->generatePlayers($config->getStarterPlayerCount() * 2, RecruitmentSource::YOUTH_INTAKE, $nationality)`
- `$this->marketPoolService->generateStaffForRole(StaffRole::MANAGER, $config->getStarterManagerCount(), $nationality)` — if count > 0
- `$this->marketPoolService->generateStaffForRole(StaffRole::COACH, $config->getStarterCoachCount(), $nationality)` — if count > 0
- `$this->marketPoolService->generateStaffForRole(StaffRole::CHAIRMAN, $config->getStarterChairmanCount(), $nationality)` — if count > 0
- `$this->marketPoolService->generateStaffForRole(StaffRole::DIRECTOR_OF_FOOTBALL, $config->getStarterDirectorOfFootballCount(), $nationality)` — if count > 0
- `$this->marketPoolService->generateStaffForRole(StaffRole::FACILITY_MANAGER, $config->getStarterFacilityManagerCount(), $nationality)` — if count > 0
- `$this->marketPoolService->generateScouts($config->getStarterScoutCount(), $nationality)`

### 3. No other changes

`InitializeController`, `ClubController`, all repositories, DTOs, and the frontend are untouched. The frontend initialization sequence (`POST /api/initialize/starter`) is unchanged.

## Files Changed

| File | Change |
|---|---|
| `src/Service/StarterPackService.php` | Inject `MarketPoolService`; add `prewarmPoolForClub()`; call at top of `initialize()` |

## Out of Scope

- Ability-range clamping in `PlayerGenerationService` (not needed with 2× buffer)
- Any pool cleanup / deduplication of the generated extras
- Changes to the pool warming command (`app:pool:warm`)
