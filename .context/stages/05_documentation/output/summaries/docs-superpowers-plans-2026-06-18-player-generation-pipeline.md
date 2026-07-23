# docs/superpowers/plans/2026-06-18-player-generation-pipeline.md

> Title: Player Generation Pipeline — Implementation Plan · 3346 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- File Map
- Task 1: `PlayerBlueprint` DTO
- Task 2: Service skeleton + `buildEntity()`
- Task 3: Implement `buildAnchors()`
- Task 4: Implement `buildAbilityTarget()`
- Task 5: Implement `buildPersonality()`
- Task 6: Implement `buildAttributes()`
- Task 7: Wire `MarketPoolService` and delete old inline logic
- Summary
- Test plan

## Summary
This documentation describes an implementation plan for a `PlayerGenerationService` that replaces inline player-generation logic in `MarketPoolService`, using a new `PlayerBlueprint` PHP 8.4 readonly DTO that accumulates player state (anchors, ability target, personality, attributes) through a sequential five-step pipeline. It follows a strict TDD checkbox format (write failing test → verify fail → implement → verify pass) intended for step-by-step execution via the `superpowers:subagent-driven-development` or `superpowers:executing-plans` skills. An agent should read this file when tasked with implementing or continuing the player generation pipeline refactor in this Symfony 8 / PHPUnit codebase, using it as the authoritative task-by-task checklist rather than inventing its own approach.
