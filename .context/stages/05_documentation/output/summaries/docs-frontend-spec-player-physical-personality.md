# docs/frontend-spec-player-physical-personality.md

> Title: Frontend Spec — Player Physical & Personality Matrix · 807 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Overview
- 1. Physical — Height & Weight
-   API Shape
-   Ranges (from PoolConfig defaults)
-   Display Guidelines
- 2. Personality Matrix
-   API Shape
-   Scale
-   Trait Descriptions
-   Recommended Display — Radar / Spider Chart
-   Alternative Display — Stat Bar List
-   Colour Banding (optional)
- 3. Endpoints That Return These Fields

## Summary
This doc specifies the API shape and display conventions for player `height`, `weight`, and `personality` fields returned by `/api/initialize`, `/api/market-data`, and `/api/squad`. Read it when implementing or reviewing frontend UI for player profiles — it covers unit ranges (145–160cm, 38–55kg), imperial conversion formulas, the 8-trait personality scale (1–20, integer, server-clamped, read-only client-side), and recommended radar-chart/stat-bar rendering with normalization and color-banding logic. Key point for an agent: these fields are server-generated and read-only on the client — never compute or write them locally, only display/convert/render them.
