# docs/superpowers/specs/2026-04-14-npc-club-generation-design.md

> Title: NPC Club Generation — Backend Design Spec · 931 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Goal
- Architecture
- Data Model
-   New Entity: `NpcClub`
-   `StaffRole` Enum — New Values
-   `FacilityTemplate.category` Enum — New Value
-   `PoolConfig` — New Senior Player Fields
- Services
-   `NpcClubGenerationService`
-   `MarketPoolService` — Extension
- API
-   `POST /api/market/consume`
- Admin UI
-   `NpcClubCrudController`
-   New admin page — `/admin/npc-clubs/generate`
-   Existing pages — minor additions
-   Sidebar
- Out of Scope (Spec B)

## Summary
This spec defines the backend-only design for Spec A of the Club Sim expansion: a persistent `NpcClub` entity (metadata-only, no player/staff FKs), new staff roles (`MANAGER`, `DIRECTOR_OF_FOOTBALL`, `FACILITY_MANAGER`), a `Stadium` facility category, senior-player pool config, and a `POST /api/market/consume` endpoint the frontend uses to remove claimed players/staff/scouts from the pool. It also covers the `NpcClubGenerationService` (tier-based facilities/reputation/balance, hybrid name generation) and `MarketPoolService` extensions for senior players, plus new admin CRUD/generation pages.

An agent should read this doc when implementing or modifying NPC club generation, the market consume endpoint, senior player pool logic, staff role/facility enums, or related admin UI — and should note it explicitly excludes Country/League entities and frontend work, which live in Spec B.
