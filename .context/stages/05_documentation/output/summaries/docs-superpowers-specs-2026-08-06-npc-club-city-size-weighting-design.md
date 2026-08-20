# docs/superpowers/specs/2026-08-06-npc-club-city-size-weighting-design.md

> Title: NPC Club City-Size Weighting — Design · 1009 words · parsed 2026-08-20T22:28:10.476Z

## Outline
- Goal
- Data Model
-   `App\Enum\CitySize` (new)
-   `NpcClubGenerationService::PLACE_NAMES_BY_COUNTRY` (restructured)
-   `SUFFIXES_BY_COUNTRY` (split into two prestige pools)
-   `NpcClub` entity (new columns)
-   Size classification rule
- Generation Algorithm
-   Weighted city selection (replaces uniform `array_rand`)
-   Suffix selection (depends on picked place's `citySize`)
-   Reputation/balance bias
- Admin-Configurable Weight Table
-   `GameConfig::npcClubSizeWeights` (new JSON column)
-   Admin route
-   Admin template
- Migration & Rollout
- Out of Scope

## Summary
This design doc (approved, 2026-08-06) restructures `NpcClubGenerationService::PLACE_NAMES_BY_COUNTRY` from flat name lists into curated per-country place data (name, population, region, capital flag), adding a new `CitySize` enum (BIG/MEDIUM/SMALL) derived from population ranking. It changes club generation so place selection is weighted by tier (via an admin-configurable `GameConfig::npcClubSizeWeights` table), suffix pools split into prestige/generic based on city size, and reputation/balance rolls get skewed within their tier range by city size — plus adds `region`, `citySize`, `populationSize`, `isCapital` columns to `NpcClub` and a new admin route/form for editing the weight table.

An agent should read this before implementing or reviewing NPC club generation changes, the `CitySize` enum, `NpcClub` entity/migration changes, or the new admin size-weights config route/template.
