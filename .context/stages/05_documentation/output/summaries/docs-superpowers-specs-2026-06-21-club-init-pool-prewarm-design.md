# docs/superpowers/specs/2026-06-21-club-init-pool-prewarm-design.md

> Title: Club Init Pool Prewarm — Design · 392 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Problem
- Goal
- Approach
- Implementation
-   1. `StarterPackService` — inject `MarketPoolService`
-   2. New private method `prewarmPoolForClub()`
-   3. No other changes
- Files Changed
- Out of Scope

## Summary
This is a design doc for a fix to `StarterPackService::initialize()` where thin nationality coverage in the shared pool can cause new clubs to receive foreign-nationality starters. It proposes prewarming the pool per-club (before existing queries run) by generating a 2× buffer of players, exact-count staff (per role), and exact-count scouts for the club's nationality via `MarketPoolService`. An agent should read this before touching `StarterPackService.php`, `MarketPoolService`, or club-initialization/starter-pack logic, since it defines the exact method signature, generation counts, and explicitly scoped-out items (no ability-range clamping, no pool cleanup/dedup, no changes to `app:pool:warm`).
