# docs/superpowers/plans/2026-05-12-initialize-endpoint-redesign.md

> Title: Initialize Endpoint Redesign Implementation Plan · 3434 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- File Map
- Task 1: Migration + Club.starterInitializedAt
- Task 2: CountryWorldPackCache Entity + Repository
- Task 3: WorldPackCacheService
- Task 4: Promote Methods in WorldInitializationService + Add buildTierPack()
- Task 5: StarterPackService
- Task 6: Refactor InitializeController
- Task 7: WarmWorldPackCommand
- Task 8: End-to-End Verification

## Summary
This is an implementation plan for replacing the monolithic `POST /api/initialize` endpoint with four separate endpoints (`starter`, `leagues`, `league/{tier}`) plus a `CountryWorldPackCache` system, so club initialization can be chunked, retried, and made timeout-safe in a Symfony/PHP 8.4/PostgreSQL app. It's organized as numbered tasks with checkbox steps (migrations, entity/repository creation, service refactors, controller changes) meant to be executed sequentially via the `subagent-driven-development` or `executing-plans` skill, with verification done manually through `lando` CLI commands and curl (no PHPUnit installed). An agent should read this file when asked to implement, resume, or review progress on the initialize-endpoint redesign work, and should follow the file map and task order exactly rather than improvising the architecture.
