# docs/superpowers/plans/2026-04-18-admin-starter-config-league-ability-ranges.md

> Title: Admin: Starter Config League Ability Ranges Implementation Plan · 745 words · parsed 2026-07-21T21:26:02.039Z

## Outline
-   Task 1: Frontend Type Update
-   Task 2: Backend Database & Entity (Backend Repo)
-   Task 3: Backend Admin Form (Backend Repo)
-   Task 4: Player Generation Logic (Backend Repo)

## Summary
This admin plan (from 2026-04-18) covers adding a `leagueAbilityRanges` JSON matrix to `StarterConfig` so admins can set player-ability min/max ranges per country and league tier. It spans four tasks: (1) a frontend TypeScript type update in `src/types/api.ts`, (2) a backend Doctrine entity + migration adding a JSON column, (3) an EasyAdmin dashboard controller/template change that dynamically renders min/max inputs per country/tier and persists them on save, and (4) wiring the configured ranges into player-generation logic (`WorldInitializationService`) with fallback defaults (10–50) when a country/tier pair is unconfigured.

An agent should read this file when implementing or modifying starter-config ability-range features — it gives concrete file paths, code snippets, and a task-by-task checklist (with commit steps) spanning both the frontend and backend repos, intended to be executed via the `subagent-driven-development` or `executing-plans` superpowers skill.
