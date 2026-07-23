# docs/event-guide.md

> Title: Event Configuration Guide: Wunderkind Factory (Fat Client) · 434 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- 📋 Configuration Format
- 🟢 Valid Targets & Impact Reference
-   1. Availability & Health
-   2. Personality Matrix (1–20 Scale)
-   3. Economic & Rating Targets
-   4. Visual State Targets
- ⚙️ Engine Processing Rules

## Summary
This doc defines the JSON mutation format used to encode event impacts on player state in the Wunderkind Factory game engine — each mutation has a `target` path and numeric `delta`. It catalogs valid targets (health/availability, the 1–20 clamped personality matrix, economic/rating fields in pence, and avatar expression codes) plus engine rules like trait clamping, weekly decay, and pence-based monetary units. An agent should consult it when authoring or validating event definitions that mutate player state, to ensure target paths, delta types, and units (e.g., wages in pence) match what `squadStore`/`GameLoop` expect.
