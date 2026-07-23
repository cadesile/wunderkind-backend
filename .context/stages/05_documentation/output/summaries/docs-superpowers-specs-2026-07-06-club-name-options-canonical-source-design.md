# docs/superpowers/specs/2026-07-06-club-name-options-canonical-source-design.md

> Title: Club Name Options: Source from NpcClubGenerationService · 644 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Context
- Design
-   1. `NpcClubGenerationService` — two new public getters
-   2. `ClubController::nameOptions()` — source from the service
-   3. Delete dead code
-   4. Net effect
- Testing
- Non-goals

## Summary
This design doc specifies moving `GET /api/club/name-options` off `ClubController`'s private, drifted `CITIES_BY_COUNTRY`/`SUFFIXES_BY_COUNTRY` constants (7 countries) onto `NpcClubGenerationService`'s canonical `PLACE_NAMES_BY_COUNTRY`/`SUFFIXES_BY_COUNTRY` data (9 countries, matching the Starter Config/Worldpack Cache/Generate-screen fixes from the same session) — via two new public getters (`getPlaceNames`, `getSuffixes`), method-injecting the service into `nameOptions()`, and deleting the dead constants.

An agent should read this before implementing or reviewing that specific fix, or before touching country-scoped name/suffix data anywhere in `ClubController` or `NpcClubGenerationService`, since it defines the exact method signatures, response-shape/fallback-behavior constraints to preserve, and required test coverage (9-country + unsupported-code fallback tests in both a service unit test and a new `ClubController` API test).
