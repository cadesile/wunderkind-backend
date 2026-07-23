# docs/api/community-stats.md

> Title: Community Stats API · 671 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Common parameters
-   Period semantics
- Common response shape
- Errors
- `GET /api/stats/most-transfers`
- `GET /api/stats/most-development`
- `GET /api/stats/most-seasons`
- `GET /api/stats/most-trophies`

## Summary
This documents four public, read-only, no-auth REST endpoints (`most-transfers`, `most-development`, `most-seasons`, `most-trophies`) that return live-computed club leaderboards. An agent should read it when implementing, consuming, or debugging these endpoints — key gotchas to note are that results are always sorted with omitted zero-activity clubs (never `value: 0`), `limit` is silently clamped to `[1,50]` rather than erroring, and `period=season` uses a per-club cutoff (each club's own last `SeasonRecord.createdAt`) rather than a shared global season window. It's also worth flagging when working on `most-seasons` specifically, since `period=season` there degenerates to showing only each club's single most recent season record due to how the filter compares a row against itself.
