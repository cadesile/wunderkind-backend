# docs/superpowers/specs/2026-07-05-community-stats-landing-page-design.md

> Title: Community Stats Landing Page Integration — Design · 942 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Context
- Scope
- 1. Backend: Public Access for `/api/stats`
- 2. Landing Page Section
-   Placement
-   Components
-   Data flow
-   Loading / error / empty states
- 3. Frontend API Spec Document
- Verification

## Summary
This is documentation summarization, not code work — no skill needed.

This spec describes making the `/api/stats/*` community stats endpoints (most-transfers, most-development, most-seasons, most-trophies) publicly readable and integrating them into the public landing page (`public/index.html`) as a new "community stats" section with a stat band, period-filterable tabs (week/month/season/all), and a 2×2 leaderboard grid — plus a written API spec doc for the mobile team at `docs/api/community-stats.md`. An agent should read this before touching `config/packages/security.yaml`'s access_control list, `public/index.html`, or `CommunityStatsController`, since it specifies the exact security.yaml rule to add, the reused vs. new CSS classes, the parallel-fetch/per-card error-handling data flow, and explicit out-of-scope items (no caching, no polling, no mobile-repo changes).
