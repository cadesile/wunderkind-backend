# docs/superpowers/plans/2026-07-06-club-name-options-canonical-source.md

> Title: Club Name Options Canonical Source Implementation Plan · 1225 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Global Constraints
-   Task 1: `NpcClubGenerationService` public getters
-   Task 2: `ClubController::nameOptions()` sources from the service, dead code removed
- Verification

## Summary
This is an implementation plan for making `GET /api/club/name-options` pull its place-name and suffix data from `NpcClubGenerationService` (the canonical source used for real NPC club generation) instead of `ClubController`'s own separate, drifted 7-country duplicate lists. It's structured as two TDD tasks: Task 1 adds public `getPlaceNames()`/`getSuffixes()` getters to `NpcClubGenerationService` exposing its existing private per-country constants, with tests covering known/unknown countries and all 9 generation-capable countries. Task 2 (partially shown) updates `ClubController::nameOptions()` to call these new getters and deletes the now-dead `CITIES_BY_COUNTRY`/`SUFFIXES_BY_COUNTRY` constants from the controller.

An agent should read this file when asked to implement or continue this specific plan (it requires the `subagent-driven-development` or `executing-plans` skill to execute), or when investigating why/how `name-options` sources its country data, since it documents the intended architecture and the exact constraints (unchanged response shape, unchanged EN-fallback behavior, no changes to `generateClubs()`'s own fallback logic).
