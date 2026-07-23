# docs/superpowers/specs/2026-06-21-remove-player-staff-club-fk-design.md

> Title: Remove Player/Staff Club FK — Design · 738 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Problem
- Goal
- Architecture
- Data Model Changes
-   `Player` entity
-   `Staff` entity
-   `Club` entity
-   Migration
- Repository Changes
-   `PlayerRepository`
-   `StaffRepository`
-   `WarmPoolCommand`
- Service Changes
-   `StarterPackService`
-   `MarketPoolService.assignToClub()`
-   `EconomicService`
-   `SyncService`
- Controller / Endpoint Changes
-   Removed endpoints
-   `MarketController` (`POST /api/market/assign`)
-   `ClubController` (`GET /api/club/status`)
-   Admin `DashboardController`
-   `CleanupAssignedEntitiesCommand` (`app:cleanup:assigned-entities`)
- Out of Scope

## Summary
This is a design doc for removing the client-authoritative FK relationship (`club_id`/`assigned_at`) from Player and Staff entities, since the backend acts as a data pool while the frontend owns the real game state; it specifies exact entity, migration, repository, service, and controller changes needed to switch to a generate→serve→delete pool lifecycle. An agent should read this before touching Player/Staff/Club models, MarketPoolService, StarterPackService, SyncService, EconomicService, or their related controllers/endpoints, to understand which fields/methods/routes are being removed and why. Note that Sponsor/Investor/Scout entities and frontend code are explicitly out of scope.
