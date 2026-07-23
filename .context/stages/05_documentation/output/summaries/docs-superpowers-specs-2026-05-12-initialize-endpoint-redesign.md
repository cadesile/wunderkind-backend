# docs/superpowers/specs/2026-05-12-initialize-endpoint-redesign.md

> Title: Initialize Endpoint Redesign · 900 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Problem
- Solution
- Endpoints
- State Tracking
-   Club entity changes
-   CountryWorldPackCache entity (new)
- Guards & Error Responses
-   POST /api/initialize/starter
-   GET /api/initialize/leagues
-   POST /api/initialize/league/{tier}
- Service Restructuring
-   `StarterPackService` (new)
-   `WorldInitializationService` (trimmed)
-   `WorldPackCacheService` (new)
-   `WarmWorldPackCommand` (new)
-   `InitializeController` (thinned)
- Migration
- Pre-Warm Command Usage
- Out of Scope

## Summary
This is a design spec (Approved, dated 2026-05-12) for splitting the monolithic `POST /api/initialize` endpoint into four sequential, independently retryable calls (`/starter`, `/leagues`, `/league/{tier}` × N), introducing a new `CountryWorldPackCache` entity so NPC squad data for a given country/tier is generated once and reused across clubs/retries. It details new/changed services (`StarterPackService`, `WorldPackCacheService`, trimmed `WorldInitializationService`), a `WarmWorldPackCommand` CLI for pre-warming countries, new club fields (`starterInitializedAt`, `worldInitializedAt`), a DB migration, and per-endpoint guard/error-status tables.

An agent should read this when working on the initialize endpoints, world/league generation, club onboarding flow, or the `country_world_pack_cache` table/migration — it's the authoritative reference for the intended request flow, state-tracking fields, and service boundaries in this redesign.
