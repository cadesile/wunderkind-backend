# Client / In-Memory State

No client-side/in-memory state management layer exists in this repo —
confirmed by search: no directories or files matching `store`, `redux`,
or `zustand` anywhere under `src/`. This is a Symfony/API Platform
backend; there is no frontend state tree here. (The real frontend, with
its own state layer, lives in a separate repo — see
`.context/shared/stack.md`.)

## The two "cache" mechanisms that do exist

1. **Symfony cache pools** — configured in `config/packages/cache.yaml`
   (`framework.cache`), with a named pool `app.leaderboard_cache`
   (`tags: true`) declared there, filesystem-backed by default per the
   config's own comments. Used via `CacheInterface`/pool injection in:
   `src/Service/LeaderboardCalculationService.php`,
   `src/Service/WorldOverviewService.php`,
   `src/Service/Admin/DashboardStatsService.php`,
   `src/Service/YouTubeFeedService.php`.
2. **`WorldPackSet` + `CountryWorldPackCache`** (actual Doctrine entities/tables, see
   `entities.md`) — **persisted**, database-backed generated world-pack payloads, keyed by
   SET and tier. Not in-memory, and no longer a cache in the usual sense: a set is claimed by
   exactly one club and never served twice, so these are single-use pre-built worlds held two
   deep per live country rather than shared rows anybody can hit. They were keyed
   country/tier/version until `Version20261117120000`.

Neither of these is "client/in-memory state" in the frontend sense — they
answer a caching question, not a state-management one. No further state
layer worth documenting was found.
