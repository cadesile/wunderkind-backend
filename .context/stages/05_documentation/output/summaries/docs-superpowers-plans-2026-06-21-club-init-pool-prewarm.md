# docs/superpowers/plans/2026-06-21-club-init-pool-prewarm.md

> Title: Club Init Pool Prewarm Implementation Plan · 1086 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Global Constraints
-   Task 1: Inject `MarketPoolService` and add `prewarmPoolForClub()`

## Summary
This plan describes adding a `prewarmPoolForClub()` method to `StarterPackService` that injects `MarketPoolService` and generates same-nationality players (2x buffer), staff, and scouts into the shared pool before `initialize()` runs its existing pool queries — ensuring nationality-matched candidates exist upfront. It's TDD-driven (test-first, single task, branch `feat/club-init-pool-prewarm`) with strict constraints: no touching controllers/repositories/DTOs, PHP must run via `lando php`, and existing constructor-injection/readonly patterns must be followed. An agent should read this when implementing or reviewing that specific prewarm feature, or when tracing why player/staff/scout pool queries in `StarterPackService::initialize()` now expect pre-seeded nationality-matched entities.
