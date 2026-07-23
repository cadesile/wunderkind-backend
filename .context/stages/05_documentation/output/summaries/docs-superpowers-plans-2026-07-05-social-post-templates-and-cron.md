# docs/superpowers/plans/2026-07-05-social-post-templates-and-cron.md

> Title: Social Media Auto-Posting from Community Stats Implementation Plan · 7668 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Global Constraints
-   Task 1: `StatCategory` enum + `SocialPostTemplate` entity + repository + migration
-   Task 2: `GameConfig.lastPostedStatCategory` field + migration
-   Task 3: `SocialPostRenderer` service + tests
-   Task 4: `SocialPostingException` + `SocialPostingService` + tests
-   Task 5: `PostCommunityStatCommand` + `SocialAccountConnectionRepository::findAllActive()` + tests
-   Task 6: `SeedSocialPostTemplatesCommand`
-   Task 7: `SocialPostTemplateCrudController` + menu registration + validation
-   Task 8: Admin preview/publish testing UI on `admin_social_connections`
-   Task 9: Manual verification pass (no code changes)
-   Task 10: Facebook/X profile URLs on App Links (`GameConfig` + admin form + `/api/app-links` + `/api/game-config`)

## Summary
This documents the implementation plan for turning `/api/stats/*` leaderboard data into automated social media posts (Facebook/X). It introduces a `SocialPostTemplate` entity keyed by stat category × platform, a `SocialPostRenderer` service, a `SocialPostingService` for publishing via existing connected accounts, a round-robin cron command tracked on `GameConfig`, and an admin CRUD + preview-then-confirm testing UI on `DashboardController`.

An agent should read this file when implementing or reviewing any part of this social-posting feature — it defines strict project conventions (PostgreSQL-only migrations via `doctrine:migrations:diff`, CSRF/ROLE_ADMIN requirements on admin POST actions, `{{token}}` templating syntax, no new `services.yaml` wiring, and exact test-style patterns to mirror from existing files like `SocialAuthControllerTest.php`). Task 1 (shown) covers the `StatCategory` enum and `SocialPostTemplate` entity/repository/migration with full interface signatures; later tasks (not shown in this excerpt) presumably cover the renderer, posting service, cron command, and admin UI.
