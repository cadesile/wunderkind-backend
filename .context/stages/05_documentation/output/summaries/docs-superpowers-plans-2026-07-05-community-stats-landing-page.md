# docs/superpowers/plans/2026-07-05-community-stats-landing-page.md

> Title: Community Stats Landing Page Implementation Plan · 2911 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Global Constraints
-   Task 1: Make `/api/stats/*` publicly accessible
-   Task 2: Add the "Community, right now" section to the landing page
-   Task 3: Write the frontend API spec for the mobile app team
- Common parameters
-   Period semantics
- Common response shape
- Errors
- `GET /api/stats/most-transfers`
- `GET /api/stats/most-development`
- `GET /api/stats/most-seasons`
- `GET /api/stats/most-trophies`
- Final verification (after all 3 tasks)

## Summary
This task is a summarization request, not creative/implementation work, so no skill applies here.

This plan makes the four `/api/stats/*` community endpoints publicly readable (Symfony `access_control` change), adds a live "Community, right now" stats section to the static marketing landing page (`public/index.html`, vanilla JS/fetch, reusing existing `.stats-band` styling), and produces an API spec doc for the mobile team — notably documenting that `period=season` cutoffs are per-club, not global. An agent should read this file when implementing or reviewing that work, and should follow it task-by-task via `superpowers:subagent-driven-development` or `superpowers:executing-plans` as the plan itself specifies. Key constraints to respect: no caching/polling/auto-refresh, no JS framework or build step, and don't touch `LeaderboardEntry`/`LeaderboardController`/`TransferLeaderboardService`.
