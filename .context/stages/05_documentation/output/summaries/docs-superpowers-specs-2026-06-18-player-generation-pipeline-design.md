# docs/superpowers/specs/2026-06-18-player-generation-pipeline-design.md

> Title: Player Generation Pipeline — Design Spec · 1049 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Overview
- Files
- PlayerBlueprint DTO
- Pipeline
-   Public API
-   Step 1 — `buildAnchors()` → PlayerBlueprint
-   Step 2 — `buildAbilityTarget()` → PlayerBlueprint
-   Step 3 — `buildPersonality()` → PlayerBlueprint
-   Step 4 — `buildAttributes()` → PlayerBlueprint
-     Pace, Technical, Vision — position-weighted random within [1, $cap]
-     Power — physical anchor + personality uplift, capped at $cap
-     Stamina — lean/fit bias + mental fortitude, capped at $cap
-     Heart — mental resilience aggregate, capped at $cap
-     Current Ability — computed last, no external cap
- Integration
- Helper: `normalise()`
- Out of Scope

## Summary
This design spec describes replacing inline player generation in `MarketPoolService` with a new `PlayerGenerationService` that runs a sequential pipeline over a readonly `PlayerBlueprint` DTO. It defines the four pipeline steps (anchors, ability target, personality traits, and derived attributes) and the exact formulas/ranges used for each field, including position-weighted stats and physical/personality-driven calculations for power and stamina.

An agent should read this file when implementing or modifying player-generation logic (biographical fields, ability/potential scaling, personality traits, or attribute calculations) in `MarketPoolService` or the new `PlayerGenerationService`/`PlayerBlueprint` classes, since it specifies exact formulas, caps, and ranges that must be preserved for correctness.
