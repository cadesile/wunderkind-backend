# docs/superpowers/plans/2026-07-13-backend-appearance.md

> Title: Backend-Owned Appearance (Avatar) Implementation Plan · 5313 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Global Constraints
- File Structure
-   Task 1: Appearance enums
-   Task 2: AppearanceGeneratorService (port of generateAppearance)
-   Task 3: Add `appearance` column + accessors to the four entities + migration
-   Task 4: prePersist subscriber — auto-fill appearance on any creation path
-   Task 5: Emit appearance in snapshots and market serializers
-   Task 6: Backfill command for existing rows
-   Task 7: `AppearanceType` custom form + data mapper
-   Task 8: Admin live preview — compositor asset, form theme, CRUD wiring
-   Task 9: Full-suite check, docs, PR
- Summary
- Frontend transition
- Self-Review Notes

## Summary
This plan describes the implementation of backend-owned appearance/avatar generation for Player, Staff, Scout, and Agent entities in the Symfony backend, replacing frontend-only avatar generation with a deterministic, admin-editable `appearance` JSON field. An agent should read it when implementing or extending avatar/appearance-related backend work (new enums, the `AppearanceGeneratorService` PHP port, the Doctrine lifecycle subscriber, snapshot/serializer changes, or the EasyAdmin form with live preview), since it defines exact field names, enum value strings, and file locations that must match the frontend `Appearance` type verbatim.

Key constraints to note: all commands run via Lando, migrations must use Postgres syntax, work happens on branch `feat/backend-appearance` (never `master`), and the plan is meant to be executed task-by-task via the `superpowers:subagent-driven-development` or `superpowers:executing-plans` skill, committing after each task.
