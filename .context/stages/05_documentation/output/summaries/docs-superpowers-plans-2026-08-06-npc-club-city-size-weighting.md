# docs/superpowers/plans/2026-08-06-npc-club-city-size-weighting.md

> Title: NPC Club City-Size Weighting Implementation Plan · 10251 words · parsed 2026-08-20T22:28:10.476Z

## Outline
- Global Constraints
-   Task 0: Create feature branch
-   Task 1: `CitySize` enum + `NpcClub` entity fields
-   Task 2: `GameConfig::npcClubSizeWeights` + tier interpolation
-   Task 3: Migration + test DB reconciliation
-   Task 4: Curated place data + back-compat accessors
-   Task 5: Remaining place names exposed via `/api/club/name-options`
-   Task 6: Split suffixes into prestige/generic pools
-   Task 7: Weighted place selection replaces uniform `array_rand`
-   Task 8: Reputation/balance city-size skew
-   Task 9: Admin-editable size-weight table
-   Task 10: Full regression pass
- Summary of Deviations from the Approved Spec (flag to user)

## Summary
This is an implementation plan for adding city-size-based weighting to NPC club generation in a Symfony game backend. It changes `PLACE_NAMES_BY_COUNTRY` from flat name lists into structured place data (name, population, region, capital flag), introduces a `CitySize` enum (BIG/MEDIUM/SMALL) derived from population percentile, and replaces uniform random place selection with admin-configurable weighted selection that biases club naming (prestige vs. generic suffixes) and reputation within each tier. It adds new columns (`region`, `citySize`, `populationSize`, `isCapital`) to the `NpcClub` entity via migration.

An agent should read this before implementing or reviewing the NPC club city-size feature — it defines the task-by-task TDD breakdown (starting with the `CitySize` enum and entity fields), required sub-skills (subagent-driven-development or executing-plans), and hard project constraints (Lando-wrapped PHP commands, dual test-DB reconciliation gotcha, Postgres-only migrations, EasyAdmin redirect pattern, `feat/` branch requirement).
