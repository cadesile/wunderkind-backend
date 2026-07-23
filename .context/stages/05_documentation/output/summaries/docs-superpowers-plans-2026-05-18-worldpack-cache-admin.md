# docs/superpowers/plans/2026-05-18-worldpack-cache-admin.md

> Title: Worldpack Cache Admin Implementation Plan · 1956 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- File Map
-   Task 1: Add `findAllOrderedByCountryAndTier()` to the repository
-   Task 2: Create `WorldPackController`
-   Task 3: Create the Twig template
-   Task 4: Add sidebar menu item in DashboardController
-   Task 5: Manual smoke test all actions

## Summary
This documentation task doesn't require a coding skill — it's a direct summarization request.

This plan describes adding a "Worldpack Cache" admin page to a Symfony/EasyAdmin backend, allowing admins to view `CountryWorldPackCache` entries (grouped by country/tier with club/player counts) and perform delete-single, delete-by-country, and regenerate-cache actions. It specifies exact file changes: a new query method on `CountryWorldPackCacheRepository`, a new `WorldPackController` with four routes, a new Twig template, and a menu-item addition to `DashboardController`. An agent should read this doc when implementing or reviewing the worldpack cache admin feature, as it provides full method/controller code, step-by-step tasks with verification commands, and commit instructions to follow task-by-task via the `subagent-driven-development` or `executing-plans` skill.
