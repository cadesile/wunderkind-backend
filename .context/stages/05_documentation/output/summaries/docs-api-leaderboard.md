# docs/api/leaderboard.md

> Title: Global Leaderboard API · 1148 words · parsed 2026-08-20T22:28:10.476Z

## Outline
- Categories
- Request
-   Period semantics
- Response
- Errors
- Caching & freshness
- Example
- Styling direction
-   Visual language (as built)
-   Interaction & accessibility

## Summary
Full leaderboard docs at docs/api/leaderboard.md — no need to re-fetch.

**Summary:** Documents the public (unauthenticated) `GET /api/leaderboard/{category}` endpoint, which serves 7 cached, server-ranked club leaderboards (hall_of_fame, club_reputation, career_earnings, golden_boot, playmaker, empire_index, fanatics) with 5-min TTL caching and periodic server-side recomputation, distinct from the always-fresh `/api/stats/*` endpoints. Covers request params (`period`, `page`, `pageSize`), response shape (`entries[]` with `rank`/`clubId`/`clubName`/`score`/`displayLabel`, plus `total`/`hasNextPage`), and key gotchas: `career_earnings` scores are in pence/cents, `golden_boot`/`playmaker` are club-level (single best performer, `displayLabel` = their name), and non-`all-time` periods only have data for the current server ISO week. An agent should read this before implementing anything that calls this endpoint, adds a new leaderboard category, or builds/matches the UI (which has a detailed pixel-arcade styling spec referencing `public/index.html`'s `#leaderboards` section).
