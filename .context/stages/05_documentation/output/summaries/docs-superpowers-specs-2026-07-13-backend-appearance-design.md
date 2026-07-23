# docs/superpowers/specs/2026-07-13-backend-appearance-design.md

> Title: Backend-Owned Appearance (Avatar) — Design · 1188 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Goal
-   The overriding constraint: trivial frontend transition
- Storage
-   Stored shape (the 10 rendered fields only)
- Enums — single source of truth
- `AppearanceGeneratorService`
-   Generation hook points
- Serialization — appearance flows out everywhere
- Admin editor with live preview
- Migration + backfill
- Out of scope
- Testing

## Summary
This design doc describes moving avatar/appearance generation and storage from the frontend into the backend for `Player`, `Staff`, `Scout`, and `Agent` entities — via a nullable JSON `appearance` column, a PHP port of the frontend's `generateAppearance()` (`AppearanceGeneratorService`), new backed enums as the shared source of truth for random generation and admin dropdowns, and serialization changes so `appearance` is included on every player/staff/market payload in a shape that's a drop-in replacement for the frontend's existing type.

An agent should read this before implementing backend appearance generation/storage, adding the admin appearance editor, or wiring `appearance` into snapshot/serialization code — it specifies exact field names, enum values, generation algorithm details (seeded RNG, hashId, role/age-based distributions), and the hard constraint that output must match the frontend `Appearance` type field-for-field so no frontend mapper changes are needed.
