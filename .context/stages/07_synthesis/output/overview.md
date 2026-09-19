# Cross-Stage Overview

For someone who has read nothing else yet.

This repo is the **Symfony 8 / PHP 8.4 / PostgreSQL 16 backend** for
*The Wunderkind Factory*, a mobile-first football-academy management
game (see `.context/shared/stack.md`). The real game client is a
separate React Native repo that plays almost entirely offline —
this backend's job is narrower than "the API for the app": it's a
**sync target and shared-world authority**, not the primary game loop.

Two very different consumers hit this backend:

1. **The mobile client**, via a set of hand-written JSON controllers
   under `/api/*` (see `.context/stages/04_interfaces/output/routes.md`)
   — auth, club sync, market/pool data, leaderboards, inbox messages,
   and the newer Competition feature.
2. **Operators**, via a heavily custom-themed EasyAdmin back office at
   `/admin/*` — CRUD over every domain entity, plus ~25 bespoke admin
   actions (config import/export, social-post scheduling, developer
   tools, world-pack cache management).

The data model (`.context/stages/03_data/output/entities.md`) centers on
`Club` (the player's academy) and its squad (`Player`, `Staff`, `Scout`,
`Agent`, `Guardian`), surrounded by a large catalog of tuning/config
singletons (`GameConfig`, `PoolConfig`, `StarterConfig`) that drive
procedural generation of NPC clubs, players, and market pools. A newer
**Competition** subsystem (added in a single large migration,
`Version20260916112510`) layers tournament brackets on top of existing
clubs without touching their core fields.

Deployment is straightforward: one Docker image, deployed identically to
`dev` and `prod` tiers behind a shared Caddy proxy, with 7 cron jobs
baked directly into the image for periodic generation/leaderboard/
competition-processing work
(`.context/stages/02_architecture/output/structure.md`).
